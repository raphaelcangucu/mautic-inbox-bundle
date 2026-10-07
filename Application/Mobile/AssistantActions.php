<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use Mautic\CampaignBundle\Entity\Campaign;
use MauticPlugin\MauticInboxBundle\Application\{InboxException,InboxQuery,ConversationActions,ReplyMode};
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Campaign\FetchCampaignTool;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Contact\FetchContactTool;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Management\ManageCampaignsTool;

final class AssistantActions
{
    public function __construct(private AssistantProposalStore $proposals,private ConversationStateRepository $states,private EntityManagerInterface $em,private ConversationAccess $access,private ConversationActions $actions,private InboxQuery $inbox,private ModerationStore $moderation,private FetchCampaignTool $campaign,private FetchContactTool $contact,private ManageCampaignsTool $manage){}
    public function publicReplyReason(int $id,User $user): ?string
    {
        $state=$this->states->find($id);if(!$state)throw new InboxException('Conversa não encontrada.',404);$this->access->assertView($state,$user);
        if(!$this->access->granted($user,'inbox:conversations:create')||!$this->access->granted($user,'meta:messages:create'))return 'permission_denied';
        $flags=$this->moderation->flags($this->inbox->summary($state));if($flags['spam']||$flags['blockedAuthor'])return 'moderated';
        return $this->actions->publicReplyBlockedReason($state);
    }
    public function preview(array $input,User $user,string $agent): array
    {
        $action=AssistantActionPolicy::normalize($input);$preview=$this->snapshot($action,$user);
        $action['snapshot']=$preview['snapshot'];unset($preview['snapshot']);
        return $this->proposals->create((int)$user->getId(),$agent,$action,$preview);
    }
    public function confirm(string $id,User $user,string $agent,callable $authorize): array
    {
        return $this->proposals->execute($id,(int)$user->getId(),$agent,$authorize,function(array $action,string $request)use($user):array{
            $current=$this->snapshot($action,$user);
            if(!hash_equals($action['snapshot'],$current['snapshot']))throw new InboxException('O alvo mudou desde a revisão. Peça uma nova proposta.',409);
            if($action['tool']==='mautic_reply_inbox'){
                $state=$this->states->find($action['id']);
                if(!$state->getAssignee()){
                    $state=$this->actions->take($state,$user,$state->getVersion());
                }
                $out=$this->actions->reply($state,$user,$action['body'],$request,null,$action['reply_mode'],$state->getVersion());
                return ['status'=>$out->getStatus(),'request_id'=>$out->getRequestId(),'conversation_id'=>$action['id'],'summary'=>$this->inbox->summary($state),'text'=>'Resposta registrada no atendimento. Status: '.$out->getStatus().'.'];
            }
            if($action['tool']==='inbox_transfer'){
                $state=$this->states->find($action['id']);$target=$this->em->find(User::class,$action['user_id']);
                $fresh=$this->actions->transition($state,$user,$state->getVersion(),'transfer',$target);
                return ['status'=>'completed','conversation_id'=>$action['id'],'summary'=>$this->inbox->summary($fresh),'text'=>'Atendimento transferido para '.$target->getName().'.'];
            }
            $detail=($this->campaign)($action['id'],false);
            $result=($this->manage)(action:$action['tool']==='campaign_update'?'update':'add_contacts',id:$action['id'],data:$action['data']??[],contactIds:$action['contact_ids']??[],confirm:true,idempotencyKey:$request,expectedDateModified:$detail['date_modified']);
            $fresh=($this->campaign)($action['id'],false);
            $membership=[];if($action['tool']==='campaign_add_contacts'){foreach($action['contact_ids'] as $contactId){$member=$this->em->getRepository(\Mautic\CampaignBundle\Entity\Lead::class)->findOneBy(['campaign'=>$action['id'],'lead'=>$contactId]);if($member)$this->em->refresh($member);$membership[]=['contact_id'=>$contactId,'associated'=>$member!==null&&!$member->getManuallyRemoved()];}}
            return ['status'=>'completed','membership'=>$membership,'result'=>$result,'campaign'=>$fresh,'text'=>'Ação aplicada à campanha #'.$action['id'].'.'];
        });
    }
    public function status(string $id,User $user,string $agent,callable $authorize): array
    {
        $result=$this->proposals->result($id,(int)$user->getId(),$agent,$authorize);
        if(!isset($result['request_id']))return $result;
        $out=$this->em->getRepository(\MauticPlugin\MauticInboxBundle\Entity\OutboundRequest::class)->findOneBy(['requestId'=>$result['request_id'],'author'=>$user]);
        $state=$this->states->find((int)($result['conversation_id']??0));
        if(!$out||!$state||$out->getConversation()->getId()!==$state->getConversation()->getId())throw new InboxException('Resultado não encontrado.',404);
        $this->access->assertView($state,$user);$this->em->refresh($out);
        return ['status'=>$out->getStatus(),'conversation_id'=>(int)$state->getId(),'request_id'=>$out->getRequestId(),'failure'=>$out->getStatus()==='failed'?'Não foi possível enviar. Confira os detalhes no atendimento.':null];
    }
    private function snapshot(array $action,User $user): array
    {
        $id=$action['id'];$tool=$action['tool'];
        if(in_array($tool,['mautic_reply_inbox','inbox_transfer'],true)){
            $state=$this->states->find($id);if(!$state)throw new InboxException('Conversa não encontrada.',404);$this->em->refresh($state);$this->access->assertView($state,$user);
            $detail=$this->inbox->detail($state,$user);$summary=$detail['conversation']??$this->inbox->summary($state);
            $conversation=$state->getConversation();$assignee=$state->getAssignee()?->getId();$fields=[];
            if($tool==='mautic_reply_inbox'){
                if($assignee!==null&&$assignee!==$user->getId())throw new InboxException('Assuma este atendimento no chat antes de responder.',409);
                if($assignee===null&&(!$this->access->granted($user,'inbox:conversations:edit')||!$this->access->granted($user,'meta:messages:edit')))throw new InboxException('Sem permissão para assumir o atendimento.',403);
                $flags=$this->moderation->flags($this->inbox->summary($state));if($flags['spam']||$flags['blockedAuthor'])throw new InboxException('Restaure o atendimento antes de responder.',422);
                $mode=ReplyMode::resolve($conversation->getChannel(),$conversation->getRecipient(),$action['reply_mode']);
                $reason=ReplyMode::instagramPublic($conversation->getChannel(),$conversation->getRecipient(),$mode)?$this->actions->publicReplyBlockedReason($state):($detail['reply_blocked_reason']??null);
                if($reason)throw new InboxException($reason,422);
                $fields=['mode'=>$mode,'body'=>$action['body'],'take_attendance'=>$assignee===null];
            }else{
                if($assignee!==$user->getId())throw new InboxException('Assuma o atendimento antes de transferir.',409);
                $target=$this->em->find(User::class,$action['user_id']);if(!$target)throw new InboxException('Operador não encontrado.',404);$this->em->refresh($target);if(!$this->access->canViewInbox($target))throw new InboxException('Operador sem acesso ao Inbox.',422);
                $fields=['user_id'=>$target->getId(),'user_name'=>$target->getName()];
            }
            $snapshot=hash('sha256',json_encode([$state->getVersion(),$conversation->getId(),$conversation->getChannel(),$assignee,$fields],JSON_THROW_ON_ERROR));
            return ['tool'=>$tool,'target'=>['id'=>$id,'name'=>$summary['contact_name']??('Conversa #'.$id),'channel'=>$conversation->getChannel()],'fields'=>$fields,'snapshot'=>$snapshot];
        }
        $entity=$this->em->find(Campaign::class,$id);if(!$entity)throw new InboxException('Campanha não encontrada.',404);$this->em->refresh($entity);$detail=($this->campaign)($id,false);
        // Preview is an authorization check too, never a promise of later access.
        $creator=$entity->getCreatedBy();$owner=$creator instanceof User?(int)$creator->getId():(int)$creator;$permission=$owner===$user->getId()?'campaign:campaigns:editown':'campaign:campaigns:editother';
        if(!$this->access->granted($user,$permission))throw new InboxException('Sem permissão para editar esta campanha.',403);
        $fields=$action['data']??[];$before=[];
        if($tool==='campaign_update'){foreach($fields as $key=>$value)$before[$key]=$detail[match($key){'allowRestart'=>'allow_restart',default=>$key}]??null;}
        else{
            $contacts=[];foreach($action['contact_ids'] as $contactId){$record=($this->contact)($contactId);$lead=$this->em->find(\Mautic\LeadBundle\Entity\Lead::class,$contactId);if(!$lead)throw new InboxException('Contato não encontrado.',404);$this->em->refresh($lead);$permissionUser=$lead->getPermissionUser();$owner=$permissionUser instanceof User?(int)$permissionUser->getId():(int)$permissionUser;if(!$this->access->granted($user,$owner===(int)$user->getId()?'lead:leads:editown':'lead:leads:editother'))throw new InboxException('Sem permissão para associar este contato.',403);$contacts[]=['id'=>$contactId,'name'=>$record['name']??trim(($record['firstname']??'').' '.($record['lastname']??''))];}
            $fields=['contacts'=>$contacts,'may_trigger_campaign'=>$entity->isPublished()];
        }
        if(!is_string($detail['date_modified']??null)||$detail['date_modified']==='')throw new InboxException('Campanha sem revisão. Atualize pelo Mautic primeiro.',409);
        return ['tool'=>$tool,'target'=>['id'=>$id,'name'=>$detail['name'],'channel'=>'mautic'],'fields'=>$fields,'before'=>$before,'snapshot'=>hash('sha256',json_encode([$detail,$fields],JSON_THROW_ON_ERROR))];
    }
}
