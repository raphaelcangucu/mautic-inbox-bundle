<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\{InboxException,InboxQuery};
use MauticPlugin\MauticInboxBundle\Application\Ai\CommentAutomationContext;
use MauticPlugin\MauticInboxBundle\Entity\{ConversationStateRepository,EventLog};
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;

/** The question only creates previews. Mutations reuse the ordinary moderation adapter. */
final class AssistantCommentModeration
{
    public function __construct(private ConversationStateRepository $states,private EntityManagerInterface $em,private ConversationAccess $access,private InboxQuery $inbox,private ModerationStore $moderation,private CommentAutomationContext $context,private PublicationContext $publications,private MetaInboxIntegration $integration){}
    public function read(int $id,User $user): array
    {
        $state=$this->states->find($id);if(!$state)throw new InboxException('Comentário não encontrado.',404);
        $this->access->assertView($state,$user);
        $source=$this->context->read($state);if(!$source)throw new InboxException('Comentário de origem indisponível.',422);
        $summary=$this->inbox->summary($state,$user);$flags=$this->moderation->flags($summary);
        $can=$this->access->granted($user,'inbox:conversations:edit')&&$this->access->granted($user,'meta:messages:edit');
        $operations=[];
        if($can)foreach(['spam','restore','block','unblock','hide','show'] as $operation){try{AssistantModerationPolicy::snapshot($source,$flags,$operation);$operations[]=$operation;}catch(InboxException){}}
        return ['id'=>$id,'name'=>$summary['contact_name'],'channel'=>$source['channel'],'account'=>$summary['asset'],'body'=>$source['body'],'context_complete'=>empty($source['body_truncated']),'origin'=>$source['origin'],'flags'=>$flags,'operations'=>$operations,'source'=>$source];
    }
    public function preview(array $action,User $user): array
    {
        $data=$this->read($action['id'],$user);
        if(!in_array($action['operation'],$data['operations'],true))throw new InboxException('Sem permissão ou suporte para esta moderação.',403);
        return ['tool'=>'inbox_moderate','target'=>['id'=>$data['id'],'name'=>$data['name'],'channel'=>$data['channel']],
            'fields'=>['operation'=>$action['operation'],'reason'=>$action['reason'],'body'=>$data['body'],'account'=>$data['account']['name'],'scope'=>in_array($action['operation'],['hide','show'],true)?'instagram':'inbox'],
            'snapshot'=>AssistantModerationPolicy::snapshot($data['source'],$data['flags'],$action['operation'])];
    }
    public function apply(array $action,User $user): array
    {
        $state=$this->states->find($action['id']);if(!$state)throw new InboxException('Comentário não encontrado.',404);
        return $this->integration->runHumanTransition($state,function()use($action,$user,$state):array{
            $current=$this->preview($action,$user);
            if(!hash_equals($action['snapshot'],$current['snapshot']))throw new InboxException('O comentário mudou desde a revisão. Peça uma nova proposta.',409);
            if(in_array($action['operation'],['hide','show'],true)){
                $detail=$this->inbox->detail($state,$user);
                $this->publications->hideInstagram($state,$detail['origins']??[],$action['operation']==='hide');
            }
            $this->moderation->apply($this->inbox->summary($state,$user),$action['operation'],(int)$user->getId());
            if(in_array($action['operation'],['spam','block'],true))$state->setNeedsResponse(false);
            if($action['operation']==='restore'&&$state->getLifecycle()==='open')$state->setNeedsResponse(true);
            $state->setVersion($state->getVersion()+1);$this->em->persist($state);
            $this->em->persist((new EventLog())->setConversation($state->getConversation())->setEventType('assistant_moderation')->setDetails(['action'=>$action['operation'],'actor'=>(int)$user->getId()]));$this->em->flush();
            return ['status'=>'completed','conversation_id'=>$action['id'],'moderation'=>$this->moderation->flags($this->inbox->summary($state,$user)),'operation'=>$action['operation'],'scope'=>$current['fields']['scope']];
        });
    }
}
