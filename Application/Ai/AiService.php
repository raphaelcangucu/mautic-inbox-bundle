<?php
namespace MauticPlugin\MauticInboxBundle\Application\Ai;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
final class AiService {
 public function __construct(private AiStore $store,private EntityManagerInterface $em,private PiClient $pi,private MetaInboxIntegration $integration){}
 public function assets(): array{return array_map(fn($a)=>['id'=>$a->getId(),'name'=>$a->getName(),'channel'=>$a->getType()->channel()->value,'external_id'=>$a->getExternalId()],array_values(array_filter($this->em->getRepository(MetaAsset::class)->findBy(['isPublished'=>true]),fn($a)=>$a->getType()!==\MauticPlugin\MauticMetaBundle\Domain\AssetType::WhatsAppBusinessAccount)));}
 public function permission(ConversationState $state): string{return $state->getConversation()->getAsset()->getId().':'.(str_starts_with($state->getConversation()->getRecipient(),'comment:')?'comment':'message');}
 /** @return array{allowed:bool,reason:?string} */
 public function availability(ConversationState $state,array $agent): array {
  $config=$this->store->config();$permission=$this->permission($state);$reason=null;
  if(empty($config['enabled']))$reason='global_disabled';
  elseif(empty($agent['enabled']))$reason='agent_disabled';
  elseif(!in_array($permission,$config['permissions'],true))$reason='global_permission';
  elseif(!in_array($permission,$agent['permissions']??[],true))$reason='agent_permission';
  return ['allowed'=>null===$reason,'reason'=>$reason];
 }
 public function allowed(ConversationState $state,array $agent): bool{return $this->availability($state,$agent)['allowed'];}
 public function assign(ConversationState $state,User $actor,string $key,int $version): void {
  $agent=$this->store->get('agent',$key);if(!$agent||!$this->allowed($state,$agent))throw new InboxException('mautic.inbox.ai.not_allowed',409);
  $health=$this->store->get('health','pi');if(empty($health['validated']))throw new InboxException('mautic.inbox.ai.validate_first',409);
  $this->integration->runHumanTransition($state,function()use($state,$actor,$key,$agent,$version){$this->em->refresh($state);if($state->getVersion()!==$version)throw new InboxException('mautic.inbox.ai.conflict',409);
   if($state->getAssignee()&&$state->getAssignee()->getId()!==$actor->getId())throw new InboxException('mautic.inbox.ai.take_first',409);
   $previous=$this->store->get('assignment',(string)$state->getId());
   if(($previous['transfers']??0)>=1&&($previous['agent']??$key)!==$key)throw new InboxException('mautic.inbox.ai.transfer_limit',409);
   $snapshot=($previous['agent']??null)===$key&&isset($previous['context'])?$previous['context']:$this->store->context($agent);
   $this->store->put('assignment',(string)$state->getId(),['agent'=>$key,'name'=>$agent['name'],'actor'=>$actor->getId(),'nonce'=>bin2hex(random_bytes(16)),'status'=>'active','count'=>$previous['count']??0,'offtopic'=>$previous['offtopic']??0,'transfers'=>($previous['transfers']??0)+(!empty($previous['agent'])&&$previous['agent']!==$key?1:0),'context'=>$snapshot,'limit'=>0,'assigned_at'=>gmdate(DATE_ATOM)]);
   $state->setAssignee(null)->setHumanTakeover(true)->setLifecycle('open')->setNeedsResponse(true)->setVersion($state->getVersion()+1);$this->em->persist($state);$this->em->persist((new EventLog())->setConversation($state->getConversation())->setActor($actor)->setEventType('ai_assigned')->setDetails(['agent'=>$agent['name']]));$this->em->flush();
  });
 }
 public function reset(ConversationState $state,User $actor,int $version): void {
  $this->integration->runHumanTransition($state,function()use($state,$actor,$version){
   $this->em->refresh($state);if($state->getVersion()!==$version)throw new InboxException('mautic.inbox.ai.conflict',409);
   $assignment=$this->store->get('assignment',(string)$state->getId());if(!$assignment)throw new InboxException('mautic.inbox.ai.not_assigned',409);$wasQueued=in_array($assignment['status']??'', ['queued','finishing'],true);
   $this->cancelPendingRuns((int)$state->getId());
   $assignment['nonce']=bin2hex(random_bytes(16));$assignment['status']='active';$assignment['count']=0;$assignment['offtopic']=0;$assignment['limit']=0;$assignment['reset_at']=gmdate(DATE_ATOM);$assignment['reset_by']=$actor->getId();
   unset($assignment['reason'],$assignment['finish_action'],$assignment['finish_reason']);
   $this->store->put('assignment',(string)$state->getId(),$assignment);
   $state->setAssignee(null)->setHumanTakeover(true)->setLifecycle('open')->setNeedsResponse($state->needsResponse()||$wasQueued)->setVersion($state->getVersion()+1);$this->em->persist($state);
   $this->em->persist((new EventLog())->setConversation($state->getConversation())->setActor($actor)->setEventType('ai_reset')->setDetails(['agent'=>$assignment['name']??$assignment['agent']]));$this->em->flush();
  });
 }
 /** @return array<string,mixed>|null */
 public function pendingReply(ConversationState $state): ?array {
  $run=$this->latestRun((int)$state->getId());if(!$run||''===trim((string)($run['text']??'')))return null;
  $job=$this->jobForRun($state,$run);$rawStatus=$job?->getStatus()??(string)($run['status']??'');
  $status=match($rawStatus){'pending'=>'queued','retry'=>'retrying','completed'=>'sent',default=>$rawStatus};
  if(in_array($status,['sent','completed','cancelled','forced'],true))return null;
  $error=$job?$this->jobError($job):trim((string)($run['error']??''));$reason=(string)($run['reason']??'');
  if('blocked'===$status&&str_contains($error,'AI assignment changed')){
   $reason=(int)$state->getLastInboundMessageId()!==(int)($run['inbound']??0)?'new_message_received':($state->getAssignee()?'human':('open'!==$state->getLifecycle()?'conversation_closed':'assignment_changed'));
  }elseif(in_array($status,['failed','blocked','uncertain'],true)&&''===$reason){$reason='delivery_failed';}
  return ['run_key'=>$run['key'],'text'=>(string)$run['text'],'status'=>$status,'raw_status'=>$rawStatus,'reason'=>$reason,'error'=>$error,'agent'=>(string)($run['agent']??''),'date'=>$run['date']??$run['failed_at']??null,'retryable'=>!in_array($status,['processing','uncertain','generating'],true)];
 }
 public function retryPending(ConversationState $state,User $actor,string $runKey,int $version): MetaOutboundJob {
  $job=$this->integration->runHumanTransition($state,function()use($state,$actor,$runKey,$version):MetaOutboundJob{
   $this->em->refresh($state);if($state->getVersion()!==$version)throw new InboxException('mautic.inbox.ai.conflict',409);
   $pending=$this->pendingReply($state);if(!$pending||$pending['run_key']!==$runKey)throw new InboxException('mautic.inbox.ai.reply_unavailable',409);
   if(!$pending['retryable'])throw new InboxException('uncertain'===$pending['status']?'mautic.inbox.ai.reply_uncertain':'mautic.inbox.ai.reply_busy',409);
   if($state->getAssignee())throw new InboxException('mautic.inbox.ai.take_first',409);
   if('open'!==$state->getLifecycle())throw new InboxException('mautic.inbox.ai.reply_unavailable',409);
   $assignment=$this->store->get('assignment',(string)$state->getId());$agent=$this->store->get('agent',$assignment['agent']??'');
   if(!$assignment||!$agent||!$this->allowed($state,$agent))throw new InboxException('mautic.inbox.ai.not_allowed',409);
   $run=$this->store->get('run',$runKey);$job=$this->jobForRun($state,['key'=>$runKey]+$run);
   if(!$job||$job->getMessageLogId()||'completed'===$job->getStatus())throw new InboxException('mautic.inbox.ai.reply_already_sent',409);
   if(!in_array($job->getStatus(),['pending','retry','failed','blocked','cancelled'],true))throw new InboxException('mautic.inbox.ai.reply_busy',409);
   $nonce=bin2hex(random_bytes(16));$assignment['nonce']=$nonce;$assignment['status']='queued';unset($assignment['reason'],$assignment['finish_action'],$assignment['finish_reason']);$this->store->put('assignment',(string)$state->getId(),$assignment);
   $payload=$job->getPayload();$payload['_ai_nonce']=$nonce;$job->setPayload($payload)->setStatus('pending')->setAttempts(0)->setAvailableAt(new \DateTimeImmutable())->setLockedAt(null)->setCompletedAt(null)->setLastError(null);
   $run['status']='queued';$run['nonce']=$nonce;$run['retried_at']=gmdate(DATE_ATOM);$run['retried_by']=$actor->getId();unset($run['reason'],$run['error'],$run['failed_at']);$this->store->put('run',$runKey,$run);
   $state->setLifecycle('open')->setVersion($state->getVersion()+1);$this->em->persist($job);$this->em->persist($state);$this->em->persist((new EventLog())->setConversation($state->getConversation())->setActor($actor)->setEventType('ai_reply_retried')->setDetails(['run_key'=>$runKey,'job_id'=>$job->getId()]));$this->em->flush();return $job;
  });
  return $job;
 }
 /** @return array<string,mixed>|null */
 private function latestRun(int $stateId): ?array {
  foreach(array_reverse($this->store->all('run'))as$run)if((int)($run['state']??0)===$stateId)return $run;return null;
 }
 /** @param array<string,mixed> $run */
 private function jobForRun(ConversationState $state,array $run): ?MetaOutboundJob {
  $job=$this->em->find(MetaOutboundJob::class,(int)($run['job']??0));if(!$job instanceof MetaOutboundJob)return null;$payload=$job->getPayload();return ($payload['_origin']??'')==='inbox_ai'&&(int)($payload['_ai_state']??0)===(int)$state->getId()?$job:null;
 }
 private function jobError(MetaOutboundJob $job): string {
  $raw=trim((string)$job->getLastError());if(''===$raw)return '';
  try{$decoded=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(is_array($decoded)&&isset($decoded['message']))return mb_substr((string)$decoded['message'],0,500);}catch(\JsonException){}return mb_substr($raw,0,500);
 }
 private function cancelPendingRuns(int $stateId): void {
  foreach($this->store->all('run')as$run){if((int)($run['state']??0)!==$stateId||in_array($run['status']??'', ['sent','completed','cancelled','forced'],true))continue;$key=(string)$run['key'];unset($run['key'],$run['revision']);$run['status']='cancelled';$run['cancelled_at']=gmdate(DATE_ATOM);$this->store->put('run',$key,$run);}
 }
 public function saveAgent(array $p): void {$key=(string)($p['key']??'');if(!preg_match('/^[a-z0-9_-]{1,80}$/',$key))$key=bin2hex(random_bytes(8));$name=trim((string)($p['name']??''));if(!$name||mb_strlen($name)>100)throw new InboxException('mautic.inbox.ai.document_invalid');$this->store->put('agent',$key,['name'=>$name,'profile'=>in_array($p['profile']??'', ['macro-support','macro-sports'],true)?$p['profile']:'macro-support','enabled'=>!empty($p['enabled']),'limit'=>0,'documents'=>array_values(array_filter((array)($p['documents']??[]),'is_string')),'permissions'=>array_values(array_filter((array)($p['permissions']??[]),'is_string'))],(int)($p['revision']??0));}
}
