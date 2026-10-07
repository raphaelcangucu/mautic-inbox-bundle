<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use Mautic\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use MauticPlugin\MauticInboxBundle\Application\{InboxQuery,InboxException};
use MauticPlugin\MauticInboxBundle\Application\Ai\{PiClient,AiStore,InternalAgentPolicy};
use MauticPlugin\MauticInboxBundle\Mcp\ReadInboxTool;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Campaign\{SearchCampaignsTool,FetchCampaignTool};
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Contact\{SearchContactsTool,FetchContactTool};
use Symfony\Component\Process\Process;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Psr\Log\LoggerInterface;

final class OperatorAssistant
{
    public function __construct(private AiStore $store, private ReadInboxTool $readInbox, private PiClient $pi, private EntityManagerInterface $em, private SearchCampaignsTool $campaigns, private FetchCampaignTool $campaign, private SearchContactsTool $contacts, private FetchContactTool $contact, private InboxQuery $inbox, private ConversationStateRepository $states, private \MauticPlugin\MauticInboxBundle\Security\ConversationAccess $access, private LoggerInterface $logger, private AssistantCampaignReports $reports, private AssistantActions $actions, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}
    /** Public provider description only: no credentials, tools, model calls or CRM reads. */
    public function privacy(): array { return $this->run(['mode'=>'privacy']); }
    /** Metadata only; tools are intersected with fresh user permissions. */
    public function agents(User $user): array
    {
        // The same request can span model planning; re-read role assignment too.
        $this->em->refresh($user);
        if($user->getRole())$this->em->refresh($user->getRole());
        if (!$this->access->canViewInbox($user)) throw new InboxException('mautic.inbox.ai.not_allowed',403);
        $records=[];
        foreach ($this->store->all('agent') as $record) {
            // Refresh managed JSON records too: an administrator may change the
            // policy while the model is planning a tool call.
            $records[]=['key'=>$record['key']]+$this->store->get('agent',$record['key']);
        }
        $configured=InternalAgentPolicy::configured($records);
        $items=[];
        foreach ($configured as $agent) {
            if (!InternalAgentPolicy::visible($agent,(int)$user->getId(),$user->getRole()?->getId(),$user->isPublished())) continue;
            $tools=InternalAgentPolicy::effectiveTools($agent,fn(string $p)=>$this->access->granted($user,$p));
            $items[]=['key'=>$agent['key'],'name'=>$agent['name'],'tools'=>$tools,'read_only'=>true,'confirmation_required'=>(bool)array_intersect($tools,InternalAgentPolicy::writes())];
        }
        // Once internal agents are configured, disabling them must not open a fallback.
        if (!$configured) {
            $legacy=['mcp_tools'=>InternalAgentPolicy::LEGACY_TOOLS];
            $items[]=['key'=>'','name'=>'Mautic Assistant','tools'=>InternalAgentPolicy::effectiveTools($legacy,fn(string $p)=>$this->access->granted($user,$p)),'read_only'=>true];
        }
        return ['items'=>$items];
    }

    private function selectAgent(array $payload, User $user): array
    {
        return InternalAgentPolicy::select($this->agents($user)['items'],$payload);
    }

    public function reply(array $payload,User $user): array
    {
        // Older beta clients remain compatible. New clients bind the user's
        // consent to the current recipient before any question or context leaves.
        if (array_key_exists('sharing_policy_id',$payload)) {
            $policy=$this->privacy();
            if (!is_string($payload['sharing_policy_id']) || !hash_equals((string)($policy['policy_id']??''),$payload['sharing_policy_id'])) { throw new InboxException('O provedor do assistente mudou. Confira e autorize o compartilhamento novamente.',409); }
        }
        $agent=$this->selectAgent($payload,$user);
        $documents=$agent['key']!==''?$this->store->context($this->store->get('agent',$agent['key'])):[];
        $message=trim((string)($payload['message']??''));
        if ($message === '' || mb_strlen($message)>4000) { throw new InboxException('mautic.inbox.ui.invalid_request_43c865',422); }
        $dir=$this->projectDir.'/var/inbox-mobile'; if(!is_dir($dir)){mkdir($dir,0700,true);}
        $lock=fopen($dir.'/assistant.lock','c+');if(!$lock){throw new InboxException('mautic.inbox.ai.pi_failed',503);}chmod($dir.'/assistant.lock',0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new InboxException('mautic.inbox.ai.pi_failed',429);}
        try {
            $history=[];foreach(array_slice((array)($payload['history']??[]),-6) as $turn){if(is_array($turn)&&in_array($turn['role']??'', ['user','assistant'],true)){$history[]=['role'=>$turn['role'],'text'=>mb_substr((string)($turn['text']??''),0,2000)];}}
            $selected=$this->states->find((int)($payload['conversation_id']??0));
            $requested=(int)($payload['conversation_id']??0);
            if ($requested && !$selected) throw new InboxException('mautic.inbox.ui.conversation_not_found_61bc81',404);
            if ($selected) $this->access->assertView($selected,$user);
            $context=$selected?['id'=>(int)$selected->getId(),'name'=>$this->inbox->summary($selected)['contact_name']]:[];
            $results=[];$names=[];
            $explicit=AssistantQueryHints::calls($message);
            $explicit=array_values(array_filter($explicit,static fn($call)=>in_array($call['tool'],$agent['tools'],true)));
            for($round=0;$round<2;$round++){
            $plan=$round===0&&$explicit?['calls'=>$explicit,'needs_followup'=>true]:$this->run(['mode'=>'plan','message'=>$message,'history'=>$history,'context'=>$context,'tools'=>array_values(array_diff($agent['tools'],InternalAgentPolicy::writes())),'documents'=>$documents,'results'=>$results]);
            // Operational metadata only: never log questions, history or CRM data.
            $this->logger->info('Inbox internal assistant plan', ['actor'=>(int)$user->getId(),'agent'=>$agent['key'],'tools'=>$agent['tools'],'calls'=>array_values(array_filter(array_map(static fn($call)=>is_array($call)&&in_array($call['tool']??null,array_column(InternalAgentPolicy::catalog(),'name'),true)?$call['tool']:null,(array)($plan['calls']??[]))))]);
            foreach(array_slice((array)($plan['calls']??[]),0,3) as $call){
                if(!is_array($call)){continue;} $tool=(string)($call['tool']??'');$query=mb_substr((string)($call['query']??''),0,120);$id=max(0,(int)($call['id']??0));$page=max(1,min(100,(int)($call['page']??1)));
                if(in_array($tool,InternalAgentPolicy::writes(),true)||!in_array($tool,$agent['tools'],true)){$results[]=['tool'=>$tool,'data'=>['error'=>'permission_denied']];continue;}
                // Role changes between planning and execution apply immediately.
                $fresh=$this->selectAgent(['agent_key'=>$agent['key']],$user);
                if(!in_array($tool,$fresh['tools'],true)){$results[]=['tool'=>$tool,'data'=>['error'=>'permission_denied']];continue;}
                $arguments=['query'=>$query,'id'=>$id,'page'=>$page];
                if($tool==='mautic_read_inbox')$arguments+=['resource'=>(string)($call['resource']??'conversations'),'filters'=>$this->inboxFilters((array)($call['filters']??[]))];
                try {
                    $result=match($tool){
                        'mautic_search_campaigns'=>($this->campaigns)($query,10,$page),
                        'mautic_search_contacts'=>($this->contacts)($query,10,$page),
                        'mautic_fetch_campaign'=>$id>0?($this->campaign)($id,false):['error'=>'missing_id'],
                        'mautic_fetch_contact'=>$id>0?($this->contact)($id):['error'=>'missing_id'],
                        'mautic_read_campaign_flow'=>$this->reports->flow($id),
                        'campaign_report'=>$this->reports->report(array_replace($call,['_user'=>$user])),
                        'campaign_comments'=>$this->reports->comments($id,$user,isset($call['date'])?(string)$call['date']:null),
                        'mautic_read_inbox'=>$this->readForAssistant($arguments['resource'],$id>0?$id:null,$arguments['filters'],$user),
                        'inbox_context'=>$selected?['conversation'=>$this->inbox->detail($selected,$user),'history'=>$this->inbox->timeline($selected,null,30,$user)]:$this->inbox->conversations($user,['kind'=>'private','queue'=>'all','needs_response'=>true,'limit'=>20]),
                    };
                    // Ephemeral WebChat tokens are never provided to the model.
                    if($tool === 'mautic_search_campaigns' && isset($result['items'])){
                        foreach($result['items'] as &$item){$entity=$this->em->find(Campaign::class,(int)($item['id']??0));if($entity instanceof Campaign){$now=new \DateTimeImmutable();$item['is_published']=$entity->isPublished();$item['active']=$entity->isPublished() && (!$entity->getPublishUp()||$entity->getPublishUp()<=$now) && (!$entity->getPublishDown()||$entity->getPublishDown()>=$now);}}unset($item);
                    }
                    $result=$this->sanitize($result);
                } catch (InboxException $e) { $result=['error'=>$e->httpStatus===403||$e->httpStatus===404?'permission_denied':'resource_unavailable']; } catch (\InvalidArgumentException) { $result=['error'=>'invalid_arguments']; } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) { $result=['error'=>'permission_denied']; } catch (HttpExceptionInterface $e) { $result=['error'=>$e->getStatusCode()===403?'permission_denied':'resource_unavailable']; }
                $this->logger->info('Inbox internal assistant tool', ['actor'=>(int)$user->getId(),'agent'=>$agent['key'],'tool'=>$tool,'failed'=>isset($result['error']),'items'=>isset($result['items'])&&is_array($result['items'])?count($result['items']):null]);
                $results[]=['tool'=>$tool,'arguments'=>$arguments,'data'=>$result];$names[]=$tool;
            }
            if(empty($plan['calls'])||empty($plan['needs_followup']))break;
            }
            $fresh=$this->selectAgent(['agent_key'=>$agent['key']],$user);
            $results=array_values(array_filter($results,static fn(array $result)=>in_array($result['tool'],$fresh['tools'],true)));
            $answer=$this->run(['mode'=>'answer','message'=>$message,'history'=>$history,'context'=>$context,'tools'=>$fresh['tools'],'documents'=>$documents,'results'=>$results]);
            $proposals=[];$proposalErrors=[];
            foreach(array_slice((array)($answer['proposals']??[]),0,3) as $draft){
                try{
                    if(!is_array($draft))throw new \InvalidArgumentException('invalid_proposal');
                    $draft=AssistantActionPolicy::normalize($draft);
                    $this->authorizeAction($draft,$user,$agent['key']);
                    $this->assertActionEvidence($draft,$results,$context);
                    $proposals[]=$this->actions->preview($draft,$user,$agent['key']);
                }catch(InboxException $e){$proposalErrors[]=$e->getMessage();}catch(\InvalidArgumentException $e){$this->logger->error('Inbox internal assistant invalid proposal',['actor'=>(int)$user->getId(),'agent'=>$agent['key'],'reason'=>$e->getMessage(),'tool'=>$draft['tool']??null,'target'=>$draft['id']??null,'fields'=>array_keys($draft)]);$proposalErrors[]='Identifique o alvo exato antes de preparar a ação.';}
            }
            if($proposalErrors)$answer['text']=($answer['text']??'')."\n\nNão foi possível preparar a ação: ".implode(' ',$proposalErrors);
            if(!is_string($answer['text']??null)||trim($answer['text'])===''){throw new InboxException('mautic.inbox.ai.pi_failed',503);}
            $this->selectAgent(['agent_key'=>$agent['key']],$user);
            return ['role'=>'assistant','text'=>mb_substr($answer['text'],0,4000),'tool'=>implode(' · ',array_unique($names))?:'Pi · leitura','read_only'=>true,'agent_key'=>$agent['key'],'agent_name'=>$agent['name'],'proposals'=>$proposals];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function confirm(array $payload,User $user): array
    {
        if(($payload['confirm']??null)!==true||!is_string($payload['proposal_id']??null))throw new InboxException('Confirme a ação revisada.',422);
        $agent=$this->selectAgent($payload,$user);
        return $this->actions->confirm($payload['proposal_id'],$user,$agent['key'],fn($action)=>$this->authorizeAction($action,$user,$agent['key']));
    }
    public function actionStatus(array $payload,User $user): array
    {
        if(!is_string($payload['proposal_id']??null))throw new InboxException('Ação inválida.',422);
        $agent=$this->selectAgent($payload,$user);
        return $this->actions->status($payload['proposal_id'],$user,$agent['key'],fn($action)=>$this->authorizeAction($action,$user,$agent['key']));
    }
    private function authorizeAction(array $action,User $user,string $key): void
    {
        $fresh=$this->selectAgent(['agent_key'=>$key],$user);
        if(!in_array($action['tool']??'',InternalAgentPolicy::writes(),true)||!in_array($action['tool'],$fresh['tools'],true))throw new InboxException('mautic.inbox.ai.not_allowed',403);
    }
    private function assertActionEvidence(array $action,array $results,array $context): void
    {
        $campaigns=[];$conversations=isset($context['id'])?[(int)$context['id']]:[];$contacts=[];$users=[];
        foreach($results as $result){
            $data=$result['data'];$tool=$result['tool'];if(isset($data['error']))continue;
            if(in_array($tool,['mautic_search_campaigns','campaign_report'],true))foreach($data['items']??[] as $row)$campaigns[]=(int)($row['id']??0);
            if($tool==='mautic_fetch_campaign')$campaigns[]=(int)($data['id']??0);
            if($tool==='mautic_read_campaign_flow')$campaigns[]=(int)($data['campaignId']??0);
            if($tool==='campaign_comments')$campaigns[]=(int)($data['campaign_id']??0);
            if($tool==='inbox_context')foreach($data['items']??[] as $row)$conversations[]=(int)($row['id']??0);
            if($tool==='mautic_search_contacts')foreach($data['items']??[] as $row)$contacts[]=(int)($row['id']??0);
            if($tool==='mautic_fetch_contact')$contacts[]=(int)($data['id']??0);
            if($tool==='mautic_read_inbox'){
                if(($result['arguments']['resource']??'')==='conversations')foreach($data['items']??[] as $row)$conversations[]=(int)($row['id']??0);
                if(($result['arguments']['resource']??'')==='conversation')$conversations[]=(int)($result['arguments']['id']??0);
                if(($result['arguments']['resource']??'')==='users')foreach($data['items']??[] as $row)$users[]=(int)($row['id']??0);
            }
        }
        $ids=str_starts_with((string)($action['tool']??''),'campaign_')?$campaigns:$conversations;
        if(!in_array($action['id']??null,$ids,true))throw new \InvalidArgumentException('unknown_target');
        foreach($action['contact_ids']??[] as $id)if(!in_array($id,$contacts,true))throw new \InvalidArgumentException('unknown_contact');
        if(isset($action['user_id'])&&!in_array($action['user_id'],$users,true))throw new \InvalidArgumentException('unknown_operator');
    }
    private function readForAssistant(string $resource,?int $id,array $filters,User $user): array
    {
        $data=($this->readInbox)($resource,$id,$filters);
        if($resource==='conversation'&&$id){
            $state=$this->states->find($id);
            if($state&&$state->getConversation()->getChannel()==='instagram'&&str_starts_with($state->getConversation()->getRecipient(),'comment:')){
                $this->access->assertView($state,$user);
                $reason=$this->actions->publicReplyReason($id,$user);
                $data['reply_modes']=['public'=>['available'=>$reason===null,'blocked_reason'=>$reason],'private'=>['available'=>($data['reply_blocked_reason']??null)===null,'blocked_reason'=>$data['reply_blocked_reason']??null]];
            }
        }
        return $data;
    }
    private function inboxFilters(array $input): array
    {
        $out=array_intersect_key($input,array_flip(['queue','channel','kind','lifecycle','search','limit','cursor','before','needs_response']));
        foreach ($out as $key=>$value) if (!is_scalar($value)&&$value!==null) unset($out[$key]);
        $out['limit']=max(1,min(20,(int)($out['limit']??20)));
        $out['queue']=$out['queue']??'all';
        return $out;
    }
    private function sanitize(mixed $value): mixed
    {
        if(!is_array($value)){return $value;}
        foreach($value as $key=>$item){if(in_array(strtolower((string)$key),['token','realtime','access_token','refresh_token','password','secret'],true)){unset($value[$key]);}else{$value[$key]=$this->sanitize($item);}}
        return $value;
    }
    private function run(array $payload): array
    {
        $process=new Process(['/usr/bin/node',$this->pi->home().'/mobile-assistant.mjs'],$this->pi->home());$process->setInput(json_encode($payload,JSON_THROW_ON_ERROR));$process->setTimeout(50);
        try {$process->run();$result=json_decode($process->getOutput(),true,32,JSON_THROW_ON_ERROR);if(!$process->isSuccessful()||!is_array($result)||isset($result['error'])){throw new \RuntimeException('assistant_unavailable');}return $result;}
        catch(\Throwable $e){throw new InboxException('mautic.inbox.ai.pi_failed',503,$e);}
    }
}
