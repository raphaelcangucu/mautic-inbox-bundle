<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\{InboxException,ReplyAvailability};
use MauticPlugin\MauticInboxBundle\Entity\{ConversationState,ConversationStateRepository,EventLog};
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset,MetaConversation};

/** Bounded CRM reads; starting a chat creates context, never sends a message. */
final class ContactDirectory
{
    public function __construct(private Connection $db, private EntityManagerInterface $em, private CorePermissions $permissions, private ConversationStateRepository $states, private PhoneNormalizer $phones, private ReplyAvailability $availability, private MetaInboxIntegration $integration) {}

    private function table(string $name): string { return (defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').$name; }
    private function allowed(User $user, string $permission): bool { return $user->isAdmin() || $this->permissions->isGranted($permission); }

    public function campaigns(User $user): array
    {
        if (!$this->allowed($user,'campaign:campaigns:viewown') && !$this->allowed($user,'campaign:campaigns:viewother')) { return []; }
        $qb=$this->db->createQueryBuilder()->select('id','name')->from($this->table('campaigns'))->where('is_published = 1')->orderBy('name')->setMaxResults(200);
        if (!$this->allowed($user,'campaign:campaigns:viewother')) { $qb->andWhere('created_by = :user')->setParameter('user',$user->getId()); }
        return array_map(static fn(array $c):array=>['id'=>(int)$c['id'],'name'=>$c['name']],$qb->executeQuery()->fetchAllAssociative());
    }

    public function search(User $user, array $filters): array
    {
        if (!$this->allowed($user,'lead:leads:viewown') && !$this->allowed($user,'lead:leads:viewother')) { throw new InboxException('Sem permissão para consultar contatos.',403); }
        $limit=max(1,min(50,(int)($filters['limit']??30)));
        $qb=$this->db->createQueryBuilder()->select('l.id','l.firstname','l.lastname','l.email','l.mobile','l.phone')->from($this->table('leads'),'l')->where('l.id > :cursor')->setParameter('cursor',max(0,(int)($filters['cursor']??0)))->orderBy('l.id')->setMaxResults($limit+1);
        if (!$this->allowed($user,'lead:leads:viewother')) { $qb->andWhere('COALESCE(l.owner_id, l.created_by) = :user')->setParameter('user',$user->getId()); }
        $q=mb_substr(trim((string)($filters['search']??'')),0,100);
        if (''!==$q) { $qb->andWhere("(LOWER(CONCAT(COALESCE(l.firstname,''),' ',COALESCE(l.lastname,''))) LIKE :query OR LOWER(l.email) LIKE :query OR l.phone LIKE :query OR l.mobile LIKE :query)")->setParameter('query','%'.mb_strtolower($q).'%'); }
        $campaign=(int)($filters['campaign_id']??0);
        if ($campaign>0) {
            if (!in_array($campaign,array_column($this->campaigns($user),'id'),true)) { throw new InboxException('Campanha indisponível para seu usuário.',403); }
            $qb->andWhere('EXISTS (SELECT 1 FROM '.$this->table('campaign_leads').' cl WHERE cl.lead_id = l.id AND cl.campaign_id = :campaign AND cl.manually_removed = 0)')->setParameter('campaign',$campaign);
        }
        $rows=$qb->executeQuery()->fetchAllAssociative();$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        $items=array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>trim(($r['firstname']??'').' '.($r['lastname']??'')) ?: ($r['email'] ?: '#'.$r['id']),'email'=>$r['email']??'','phone'=>$r['mobile'] ?: ($r['phone']??'')],$rows);
        return ['items'=>$items,'next_cursor'=>$more?(string)end($items)['id']:null];
    }

    private function contact(int $id, User $user): Lead
    {
        $contact=$this->em->find(Lead::class,$id);
        if (!$contact instanceof Lead) { throw new InboxException('Contato não encontrado.',404); }
        $permissionUser=$contact->getPermissionUser();
        $permissionUserId=$permissionUser instanceof User ? $permissionUser->getId() : $permissionUser;
        if (!$this->allowed($user,'lead:leads:viewother') && !($this->allowed($user,'lead:leads:viewown') && (int)$permissionUserId===$user->getId())) { throw new InboxException('Sem permissão para consultar este contato.',403); }
        return $contact;
    }

    public function detail(int $id, User $user): array
    {
        $contact=$this->contact($id,$user);$campaigns=$this->campaigns($user);
        $members=$this->db->fetchFirstColumn('SELECT campaign_id FROM '.$this->table('campaign_leads').' WHERE lead_id = ? AND manually_removed = 0',[$id]);
        return ['id'=>$id,'name'=>$contact->getName() ?: $contact->getEmail() ?: '#'.$id,'email'=>$contact->getEmail()??'','phone'=>$contact->getMobile() ?: $contact->getPhone() ?: '', 'campaigns'=>array_values(array_filter($campaigns,static fn(array $c):bool=>in_array((string)$c['id'],array_map('strval',$members),true))),'channels'=>$this->options($contact,$user)];
    }

    private function options(Lead $contact, User $user): array
    {
        $items=[];$qrAssets=[];
        $qrType=defined(AssetType::class.'::WhatsAppQrSession') ? constant(AssetType::class.'::WhatsAppQrSession') : null;
        $canStart=$this->allowed($user,'inbox:conversations:create') && $this->allowed($user,'meta:messages:create');
        foreach ($this->states->createQueryBuilder('s')->join('s.conversation','c')->where('c.contact = :contact')->andWhere('c.recipient NOT LIKE :comments')->setParameter('contact',$contact)->setParameter('comments','comment:%')->orderBy('c.lastMessageAt','DESC')->setMaxResults(50)->getQuery()->getResult() as $state) {
            $c=$state->getConversation();$reason=$this->availability->reason($state);
            $items[]=['key'=>'state:'.$state->getId(),'state_id'=>$state->getId(),'asset_id'=>$c->getAsset()->getId(),'channel'=>$c->getChannel(),'name'=>$c->getAsset()->getName(),'available'=>$canStart && null===$reason,'reason'=>$reason];
            if ($qrType && $qrType===$c->getAsset()->getType()) { $qrAssets[]=$c->getAsset()->getId(); }
        }
        if (null===$qrType) { return $items; }
        $phone=(string)($contact->getMobile() ?: $contact->getPhone());
        foreach ($this->em->getRepository(MetaAsset::class)->findBy(['type'=>$qrType,'status'=>'active','isPublished'=>true]) as $asset) {
            if (in_array($asset->getId(),$qrAssets,true)) { continue; }
            $reason=null;
            try { $this->phones->normalizeImported($phone,(string)($asset->getSettings()['default_region']??'BR')); } catch (\InvalidArgumentException) { $reason='Cadastre um telefone válido no contato.'; }
            if ('connected'!==($asset->getSettings()['whatsqr_session_status']??'')) { $reason='Este número QR não está conectado.'; }
            $items[]=['key'=>'qr:'.$asset->getId(),'state_id'=>null,'asset_id'=>$asset->getId(),'channel'=>'whatsapp','name'=>$asset->getName().' · QR','available'=>$canStart && null===$reason,'reason'=>$reason];
        }
        return $items;
    }

    public function start(int $id, User $user, array $payload): ConversationState
    {
        $contact=$this->contact($id,$user);
        if (!$this->allowed($user,'inbox:conversations:create') || !$this->allowed($user,'meta:messages:create')) { throw new InboxException('Sem permissão para iniciar atendimento.',403); }
        $options=$this->options($contact,$user);$selected=null;
        foreach($options as $option){if($option['key']===($payload['channel_key']??'')){$selected=$option;break;}}
        if (!$selected || !$selected['available']) { throw new InboxException($selected['reason']??'Canal indisponível para este contato.',422); }
        if ($selected['state_id']) { return $this->states->find($selected['state_id']); }
        $asset=$this->em->find(MetaAsset::class,$selected['asset_id']);
        $phone=$this->phones->normalizeImported((string)($contact->getMobile() ?: $contact->getPhone()),(string)($asset->getSettings()['default_region']??'BR'));
        $context=(new ConversationState())->setConversation((new MetaConversation())->setAsset($asset)->setChannel('whatsapp')->setRecipient($phone));
        return $this->integration->runHumanTransition($context,function()use($asset,$phone,$contact,$user):ConversationState{
            $repo=$this->em->getRepository(MetaConversation::class);$conversation=null;
            foreach($this->phones->equivalentRecipients($phone,(string)($asset->getSettings()['default_region']??'BR')) as $alias){$conversation=$repo->findOneBy(['asset'=>$asset,'channel'=>'whatsapp','recipient'=>$alias]);if($conversation){break;}}
            if($conversation && $conversation->getContact() && $conversation->getContact()->getId()!==$contact->getId()){throw new InboxException('Este telefone já está vinculado a outro contato. Revise o cadastro.',409);}
            if(!$conversation){$conversation=(new MetaConversation())->setAsset($asset)->setChannel('whatsapp')->setRecipient($phone);$this->em->persist($conversation);}
            $conversation->setContact($contact);
            $state=$conversation->getId() ? $this->states->findOneBy(['conversation'=>$conversation]) : null;
            if(!$state){$state=(new ConversationState())->setConversation($conversation)->setAssignee($user)->setHumanTakeover(true)->setNeedsResponse(false);$this->em->persist($state);$this->em->persist((new EventLog())->setConversation($conversation)->setActor($user)->setEventType('created')->setDetails(['source'=>'mobile_contact']));}
            $this->em->flush();return $state;
        });
    }
}
