<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Ai;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Application\{InboxException, ReplyAvailability};
use MauticPlugin\MauticInboxBundle\Application\Mobile\{ModerationStore, PublicationContext};
use MauticPlugin\MauticInboxBundle\Entity\{CommentContext, ConversationState, EventLog};
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Entity\{MetaMessage, MetaOutboundJob};
use Mautic\UserBundle\Entity\User;

/** Dedicated comment pipeline; disabled by default, persisted in the existing AiRecord table. */
final class CommentAutomation
{
    public function __construct(private AiStore $store, private EntityManagerInterface $em, private AiService $agents,
        private PiClient $pi, private CommentAutomationContext $context, private CommentAutomationExecutor $executor,
        private PublicationContext $publications, private ModerationStore $moderation, private ReplyAvailability $availability,
        private MetaInboxIntegration $integration, private OutboundQueue $queue) {}

    public function configurations(): array { return $this->store->all('comment_config'); }

    public function runs(int $page=1): array
    {
        return $this->store->page('comment_run',max(1,$page),25);
    }

    public function saveConfig(array $input, int $actor): array
    {
        $key=(string)($input['key']??'');
        if (!preg_match('/^[a-z0-9_-]{1,80}$/D',$key)) throw new InboxException('mautic.inbox.ai.automation_invalid');
        $agent=$this->store->get('agent',$key);
        if (!$agent || InternalAgentPolicy::internal($agent)) throw new InboxException('mautic.inbox.ai.automation_invalid');
        $old=$this->store->get('comment_config',$key);
        try {$config=CommentAutomationPolicy::normalize($input);}catch(\DomainException){throw new InboxException('mautic.inbox.ai.automation_invalid');}
        if ($config['mode']!=='off') {
            $global=$this->store->config(); $assets=$this->agents->assets();
            $allowed=array_column(array_filter($assets,static fn($a)=>in_array($a['channel'],['instagram','facebook'],true)),'id');
            if (!$config['asset_ids'] || !$this->ruleAvailable($config,$agent) || empty($this->store->get('health','pi')['validated'])
                || ($config['mode']==='automatic' && !$config['mark_spam_enabled'] && !$config['public_reply_enabled'])) throw new InboxException('mautic.inbox.ai.automation_not_ready');
            foreach($config['asset_ids'] as $asset) if (!in_array($asset,$allowed,true) || !CommentAutomationPolicy::permission($config,$global,$agent,$asset)) throw new InboxException('mautic.inbox.ai.automation_not_ready');
            // One rule owns each account, so two agents cannot race to classify/reply.
            foreach($this->configurations() as $other) if ($other['key']!==$key && ($other['mode']??'off')!=='off' && array_intersect($config['asset_ids'],$other['asset_ids']??[])) throw new InboxException('mautic.inbox.ai.automation_account_conflict',409);
        }
        $config['cursor']=(int)($old['cursor']??0);
        if (($old['mode']??'off')==='off' && $config['mode']!=='off') {
            $last=$this->em->getRepository(MetaMessage::class)->findOneBy(['direction'=>'inbound','messageType'=>'comment'],['id'=>'DESC']);
            $config['cursor']=(int)($last?->getId()??0);
        }
        $config+=['actor'=>$actor,'updated_at'=>gmdate(DATE_ATOM),'paused'=>false];
        $record=$this->store->put('comment_config',$key,$config,(int)($input['revision']??0));
        return ['key'=>$key,'revision'=>$record->getRevision()]+$config;
    }

    private function ruleAvailable(array $config,array $agent): bool
    {
        $document=$this->store->get('document',$config['document_key']??'')['published']??[];
        return !empty($document['body']) && ($document['version']??0)===($config['document_version']??0);
    }

    /** Returns a fresh authorized source, also used immediately before each side effect. */
    private function current(ConversationState $state,array $expected): ?array
    {
        $record=$this->store->find('comment_config',$expected['key']);
        if (!$record || $record->getRevision()!==$expected['revision']) return null;
        $config=$record->getData(); $agent=$this->store->get('agent',$expected['key']);
        $source=$this->context->read($state);
        if (!$source || !$source['asset_active'] || !CommentAutomationPolicy::permission($config,$this->store->config(),$agent,$source['asset_id'])
            || !$this->ruleAvailable($config,$agent) || empty($this->store->get('health','pi')['validated'])) return null;
        $source['context_versions']=array_map(static fn($d)=>[$d['key'],$d['version']],$this->context->documents($config,$agent));
        return $source;
    }

    public function work(): array
    {
        $db=$this->em->getConnection();
        if (1!==(int)$db->fetchOne("SELECT GET_LOCK('inbox_comment_worker',0)")) return ['processed'=>0];
        $processed=0;
        try {
            foreach($this->configurations() as $config) {
                if (($config['mode']??'off')==='off' || !empty($config['paused'])) continue;
                $contexts=$this->em->createQueryBuilder()->select('cc')->from(CommentContext::class,'cc')->join('cc.message','m')->join('cc.publicConversation','c')
                    ->where('m.id > :cursor AND c.asset IN (:assets)')->setParameter('cursor',$config['cursor'])->setParameter('assets',$config['asset_ids'])
                    ->orderBy('m.id','ASC')->setMaxResults(10)->getQuery()->getResult();
                foreach($contexts as $context) {
                    if ($processed>=10) break 2;
                    $state=$this->em->getRepository(ConversationState::class)->findOneBy(['conversation'=>$context->getPublicConversation()]);
                    if (!$state) { $this->advance($config,(int)$context->getMessage()->getId()); continue; }
                    $source=$this->current($state,$config);
                    if (!$source) { $this->advance($config,(int)$context->getMessage()->getId()); continue; }
                    $key=CommentAutomationPolicy::key($source['asset_id'],$source['comment_id']);
                    if ($this->store->get('comment_run',$key) || !CommentAutomationPolicy::eligible($source,$config)) { $this->advance($config,(int)$context->getMessage()->getId()); continue; }
                    if (!$this->reserveBudget($config,'classifications')) break;
                    ++$processed;
                    $public=$this->publications->resolve($state,[$source['origin']]);
                    $source['caption']=mb_substr((string)($public['items'][0]['caption']??''),0,5000);
                    $agent=$this->store->get('agent',$config['key']); $documents=$this->context->documents($config,$agent);
                    $body=implode("\n\n",array_column($documents,'body'));
                    preg_match_all('~https://[^\s<>\]"\x27]+~u',$body,$links); $source['allowed_links']=array_values(array_unique(array_map(static fn($link)=>rtrim($link,'.!,;)'),$links[0])));
                    $run=$this->executor->process($config,$source,null,
                        fn()=>$this->pi->call('comment',['model'=>$this->store->config()['model'],'context'=>$body,'comment'=>['text'=>$source['body'],'caption'=>$source['caption']]]),
                        fn()=>$this->current($state,$config), fn($run)=>$this->persist($run),
                        fn($run,$fresh)=>$this->mark($state,$config,$run,$fresh), fn($run,$fresh)=>$this->enqueue($state,$config,$run,$fresh));
                    if (in_array($run['status']??'', ['failed','uncertain'],true)) {
                        $this->pause($config,'execution_failed');
                        $this->advance($config,(int)$context->getMessage()->getId());
                        break;
                    }
                    $this->advance($config,(int)$context->getMessage()->getId());
                }
            }
        } finally { $db->fetchOne("SELECT RELEASE_LOCK('inbox_comment_worker')"); }
        return ['processed'=>$processed];
    }

    private function advance(array &$config,int $id): void
    {
        // Watermarks are bookkeeping, not policy edits: keep the configuration revision stable.
        if(!$this->adminLock())return;
        try {
            $record=$this->store->find('comment_config',$config['key']);
            if (!$record || $record->getRevision()!==$config['revision']) return;
            $data=$record->getData();$data['cursor']=max((int)$data['cursor'],$id);
            $record->checkpoint($data);$this->em->flush();$config['cursor']=$data['cursor'];
        } finally {$this->adminUnlock();}
    }

    private function reserveBudget(array $config,string $counter): bool
    {
        if(!$this->adminLock())return false;
        try {
        $key=hash('sha256',$config['key'].':'.gmdate('Y-m-d-H'));$budget=$this->store->get('comment_budget',$key)+['classifications'=>0,'replies'=>0,'last_reply'=>0];
        $limit=$counter==='replies' ? $config['reply_limit'] : $config['classify_limit'];
        if ($budget[$counter]>=$limit || ($counter==='replies' && time()-$budget['last_reply']<$config['min_interval'])) return false;
        ++$budget[$counter];if($counter==='replies')$budget['last_reply']=time();$this->store->put('comment_budget',$key,$budget);return true;
        } finally {$this->adminUnlock();}
    }

    private function persist(array $run): void
    {
        $key=$run['key'];unset($run['key']);$this->store->put('comment_run',$key,$run);
    }

    private function mark(ConversationState $state,array $config,array $run,array $source): void
    {
        $this->locked($state,$config,function()use($state,$config,$run,$source){
            $this->assertFresh($state,$config,$run);
            $this->moderation->apply(['id'=>$state->getId(),'channel'=>$source['channel'],'asset'=>['id'=>$source['asset_id']],'recipient'=>$source['recipient']],'spam',(int)$config['actor']);
            $state->setNeedsResponse(false)->setVersion($state->getVersion()+1);$this->em->persist($state);
            $this->em->persist((new EventLog())->setConversation($state->getConversation())->setEventType('comment_auto_spam')->setDetails(['comment_id'=>$source['comment_id'],'rule'=>$run['document_key'],'version'=>$run['document_version']]));$this->em->flush();
        });
    }

    private function enqueue(ConversationState $state,array $config,array $run,array $source): int
    {
        return $this->locked($state,$config,function()use($state,$config,$run,$source):int{
            $this->assertFresh($state,$config,$run);
            if ($this->availability->reason($state,'public') || !$this->reserveBudget($config,'replies')) throw new \DomainException('public_reply_unavailable_or_limit');
            $job=$this->queue->enqueue($state->getConversation()->getAsset(),CommentAutomationPolicy::operation($source['channel']),
                ['recipient'=>$source['comment_id'],'text'=>$run['text'],'_origin'=>'inbox_ai','_comment_automation'=>$run['key'],
                    '_ai_state'=>$state->getId(),'_inbox_conversation_id'=>$state->getConversation()->getId()],null,1,'inbox-comment:'.$run['key']);
            return (int)$job->getId();
        });
    }

    private function locked(ConversationState $state,array $config,callable $action): mixed
    {
        $db=$this->em->getConnection();if(1!==(int)$db->fetchOne("SELECT GET_LOCK('inbox_ai_admin',35)"))throw new \RuntimeException('configuration_lock_unavailable');
        try{return $this->integration->runHumanTransition($state,$action);}finally{$db->fetchOne("SELECT RELEASE_LOCK('inbox_ai_admin')");}
    }

    private function assertFresh(ConversationState $state,array $config,array $run): void
    {
        $fresh=$this->current($state,$config);
        if (!$fresh || !CommentAutomationPolicy::eligible($fresh,$config) || $run['state_version']!==$fresh['state_version'] || $run['fingerprint']!==CommentAutomationPolicy::fingerprint($fresh)) throw new \DomainException('comment_context_changed');
    }

    private function pause(array $config,string $reason): void
    {
        if(!$this->adminLock())return;
        try {
        $record=$this->store->find('comment_config',$config['key']);if(!$record || $record->getRevision()!==$config['revision'])return;
        $data=$record->getData();$data['paused']=true;$data['pause_reason']=$reason;$record->checkpoint($data);$this->em->flush();
        } finally {$this->adminUnlock();}
    }

    private function adminLock(): bool {return 1===(int)$this->em->getConnection()->fetchOne("SELECT GET_LOCK('inbox_ai_admin',35)");}
    private function adminUnlock(): void {$this->em->getConnection()->fetchOne("SELECT RELEASE_LOCK('inbox_ai_admin')");}

    public function review(array $input,User $actor): array
    {
        $key=(string)($input['run_key']??'');$record=$this->store->find('comment_run',$key);$run=$record?->getData();
        if (!$run || $record->getRevision()!==(int)($input['revision']??0) || $run['status']!=='review') throw new InboxException('mautic.inbox.ai.conflict',409);
        if (!in_array($input['decision']??'', ['approve','reject'],true)) throw new InboxException('mautic.inbox.ai.automation_invalid');
        if (($input['decision']??'')==='reject') {$run['status']='rejected';$run['reviewed_by']=$actor->getId();$this->persist(['key'=>$key]+$run);return ['status'=>'rejected'];}
        $state=$this->em->find(ConversationState::class,$run['state']);$config=$this->store->find('comment_config',$run['config_key']);
        if (!$state || !$config || $config->getRevision()!==$run['config_revision']) throw new InboxException('mautic.inbox.ai.conflict',409);
        $policy=['key'=>$run['config_key'],'revision'=>$config->getRevision()]+$config->getData();
        $source=$this->current($state,$policy);if(!$source)throw new InboxException('mautic.inbox.ai.automation_not_ready');
        // Approvals execute only the original safe decision, never arbitrary model/tool actions.
        if(!in_array($run['decision'],['spam','legitimate'],true))throw new InboxException('mautic.inbox.ai.automation_not_ready');
        $run['key']=$key;$run['reviewed_by']=$actor->getId();
        try {$result=$this->executor->execute($policy,$run,fn()=>$this->current($state,$policy),fn($r)=>$this->persist($r),fn($r,$s)=>$this->mark($state,$policy,$r,$s),fn($r,$s)=>$this->enqueue($state,$policy,$r,$s));}
        catch(\Throwable $error){
            if($error instanceof \DomainException && $error->getMessage()==='public_reply_unavailable_or_limit') {
                $run['status']='review';$run['reason']='public_reply_unavailable_or_limit';
            } elseif($error instanceof \DomainException && $error->getMessage()==='comment_context_changed') {
                $run['status']='cancelled';$run['reason']='context_or_authorization_changed';
            } else {
                $run['status']=str_starts_with($run['status'],'executing')?'uncertain':'failed';
                $run['reason']='execution_failed';$this->pause($policy,'execution_failed');
            }
            $this->persist($run);$result=$run;
        }
        return $result;
    }
}
