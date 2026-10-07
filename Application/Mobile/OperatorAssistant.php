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

final class OperatorAssistant
{
    public function __construct(private AiStore $store, private ReadInboxTool $readInbox, private PiClient $pi, private EntityManagerInterface $em, private SearchCampaignsTool $campaigns, private FetchCampaignTool $campaign, private SearchContactsTool $contacts, private FetchContactTool $contact, private InboxQuery $inbox, private ConversationStateRepository $states, private \MauticPlugin\MauticInboxBundle\Security\ConversationAccess $access, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}
    /** Public provider description only: no credentials, tools, model calls or CRM reads. */
    public function privacy(): array { return $this->run(['mode'=>'privacy']); }
    /** Metadata only; tools are intersected with fresh user permissions. */
    public function agents(User $user): array
    {
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
            $items[]=['key'=>$agent['key'],'name'=>$agent['name'],'tools'=>$tools,'read_only'=>true];
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
            $plan=$this->run(['mode'=>'plan','message'=>$message,'history'=>$history,'context'=>$context,'tools'=>$agent['tools'],'documents'=>$documents]);
            $results=[];$names=[];
            foreach(array_slice((array)($plan['calls']??[]),0,3) as $call){
                if(!is_array($call)){continue;} $tool=(string)($call['tool']??'');$query=mb_substr((string)($call['query']??''),0,120);$id=max(0,(int)($call['id']??0));$page=max(1,min(100,(int)($call['page']??1)));
                if(!in_array($tool,$agent['tools'],true)){$results[]=['tool'=>$tool,'data'=>['error'=>'permission_denied']];continue;}
                // Role changes between planning and execution apply immediately.
                $fresh=$this->selectAgent(['agent_key'=>$agent['key']],$user);
                if(!in_array($tool,$fresh['tools'],true)){$results[]=['tool'=>$tool,'data'=>['error'=>'permission_denied']];continue;}
                try {
                    $result=match($tool){
                        'mautic_search_campaigns'=>($this->campaigns)($query,10,$page),
                        'mautic_search_contacts'=>($this->contacts)($query,10,$page),
                        'mautic_fetch_campaign'=>$id>0?($this->campaign)($id,false):['error'=>'missing_id'],
                        'mautic_fetch_contact'=>$id>0?($this->contact)($id):['error'=>'missing_id'],
                        'mautic_read_inbox'=>($this->readInbox)((string)($call['resource']??'conversations'),$id>0?$id:null,$this->inboxFilters((array)($call['filters']??[]))),
                        'inbox_context'=>$selected?['conversation'=>$this->inbox->detail($selected,$user),'history'=>$this->inbox->timeline($selected,null,30,$user)]:$this->inbox->conversations($user,['kind'=>'private','queue'=>'all','needs_response'=>true,'limit'=>20]),
                    };
                    // Ephemeral WebChat tokens are never provided to the model.
                    if($tool === 'mautic_search_campaigns' && isset($result['items'])){
                        foreach($result['items'] as &$item){$entity=$this->em->find(Campaign::class,(int)($item['id']??0));if($entity instanceof Campaign){$now=new \DateTimeImmutable();$item['is_published']=$entity->isPublished();$item['active']=$entity->isPublished() && (!$entity->getPublishUp()||$entity->getPublishUp()<=$now) && (!$entity->getPublishDown()||$entity->getPublishDown()>=$now);}}unset($item);
                    }
                    $result=$this->sanitize($result);
                } catch (InboxException $e) { $result=['error'=>$e->httpStatus===403||$e->httpStatus===404?'permission_denied':'resource_unavailable']; } catch (\InvalidArgumentException) { $result=['error'=>'invalid_arguments']; } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) { $result=['error'=>'permission_denied']; } catch (HttpExceptionInterface $e) { $result=['error'=>$e->getStatusCode()===403?'permission_denied':'resource_unavailable']; }
                $results[]=['tool'=>$tool,'arguments'=>['query'=>$query,'id'=>$id,'page'=>$page],'data'=>$result];$names[]=$tool;
            }
            $fresh=$this->selectAgent(['agent_key'=>$agent['key']],$user);
            $results=array_values(array_filter($results,static fn(array $result)=>in_array($result['tool'],$fresh['tools'],true)));
            $answer=$this->run(['mode'=>'answer','message'=>$message,'history'=>$history,'context'=>$context,'tools'=>$fresh['tools'],'documents'=>$documents,'results'=>$results]);
            if(!is_string($answer['text']??null)||trim($answer['text'])===''){throw new InboxException('mautic.inbox.ai.pi_failed',503);}
            return ['role'=>'assistant','text'=>mb_substr($answer['text'],0,4000),'tool'=>implode(' · ',array_unique($names))?:'Pi · leitura','read_only'=>true,'agent_key'=>$agent['key'],'agent_name'=>$agent['name']];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
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
