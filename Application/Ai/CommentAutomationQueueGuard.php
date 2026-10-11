<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Ai;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\{ConversationState, EventLog};
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/** Called under the same configuration/conversation locks as human takeover. */
final class CommentAutomationQueueGuard
{
    public function __construct(private AiStore $store, private CommentAutomationContext $context, private EntityManagerInterface $em) {}

    public static function allowed(array $run,array $config,array $source,array $global,array $agent,array $job): bool
    {
        return $run['status']==='queued' && ($config['mode']==='automatic' || ($config['mode']==='review' && !empty($run['reviewed_by'])))
            && !empty($config['public_reply_enabled']) && CommentAutomationPolicy::permission($config,$global,$agent,$source['asset_id'])
            && CommentAutomationPolicy::eligible($source,$config) && !empty($source['asset_active'])
            && $run['config_revision']===$config['revision'] && $run['fingerprint']===CommentAutomationPolicy::fingerprint($source)
            && $run['state_version']===$source['state_version'] && $job['asset_id']===$source['asset_id']
            && $job['operation']===CommentAutomationPolicy::operation($source['channel']) && $job['recipient']===$source['comment_id']
            && $job['text']===$run['text'] && $job['id']===($run['job']??null);
    }

    public function execute(ConversationState $state,MetaOutboundJob $job,callable $operation): mixed
    {
        $key=(string)($job->getPayload()['_comment_automation']??'');$run=$this->store->get('comment_run',$key);
        $record=$this->store->find('comment_config',$run['config_key']??'');
        $config=$record ? ['revision'=>$record->getRevision()]+$record->getData() : [];
        $source=$this->context->read($state);$agent=$this->store->get('agent',$run['config_key']??'');
        $document=$this->store->get('document',$run['document_key']??'')['published']??[];
        if($source)$source['context_versions']=array_map(static fn($d)=>[$d['key'],$d['version']],$this->context->documents($config,$agent));
        $payload=$job->getPayload();
        if (!$run || !$config || !$source || ($document['version']??0)!==($run['document_version']??-1)
            || ($config['document_key']??'')!==$run['document_key']
            || empty($this->store->get('health','pi')['validated']) || !self::allowed($run,$config,$source,$this->store->config(),$agent,
                ['id'=>$job->getId(),'asset_id'=>$job->getAsset()->getId(),'operation'=>$job->getOperation(),'recipient'=>$payload['recipient']??'','text'=>$payload['text']??''])) {
            if ($run && $run['status']==='queued') {$run['status']='cancelled';$run['reason']='context_or_authorization_changed';$this->store->put('comment_run',$key,$run);}
            throw new \DomainException('Comment automation paused: authorization or comment changed.');
        }
        // Persist before the remote call. If the process dies here, recovery cannot send again.
        $run['status']='sending';$this->store->put('comment_run',$key,$run);
        try {$result=$operation();}
        catch(\Throwable $exception){$run['status']='uncertain';$run['reason']='delivery_not_confirmed';$this->store->put('comment_run',$key,$run);throw $exception;}
        $run['status']='sent';$run['completed_at']=gmdate(DATE_ATOM);$this->store->put('comment_run',$key,$run);
        $state->setNeedsResponse(false)->setVersion($state->getVersion()+1);$this->em->persist($state);
        $this->em->persist((new EventLog())->setConversation($state->getConversation())->setEventType('comment_auto_reply')->setDetails(['comment_id'=>$run['comment_id'],'rule'=>$run['document_key'],'version'=>$run['document_version']]));$this->em->flush();
        return $result;
    }

    public function failed(MetaOutboundJob $job): void
    {
        $key=(string)($job->getPayload()['_comment_automation']??'');$run=$this->store->get('comment_run',$key);if(!$run)return;
        if(!in_array($run['status'],['cancelled','sent'],true)){
            // A recovered job blocked after a process crash may already have reached Meta.
            $run['status']=$job->getStatus()==='blocked' && $run['status']==='queued' ? 'cancelled' : 'uncertain';
            $run['reason']='delivery_not_confirmed';$this->store->put('comment_run',$key,$run);
        }
        $record=$this->store->find('comment_config',$run['config_key']);
        if($record && $record->getRevision()===$run['config_revision'] && $run['status']!=='cancelled'){
            $config=$record->getData();$config['paused']=true;$config['pause_reason']='delivery_not_confirmed';$record->checkpoint($config);$this->em->flush();
        }
    }
}
