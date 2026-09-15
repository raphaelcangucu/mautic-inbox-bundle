<?php
namespace MauticPlugin\MauticInboxBundle\Application\Ai;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
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
   $assignment['nonce']=bin2hex(random_bytes(16));$assignment['status']='active';$assignment['count']=0;$assignment['offtopic']=0;$assignment['limit']=0;$assignment['reset_at']=gmdate(DATE_ATOM);$assignment['reset_by']=$actor->getId();
   unset($assignment['reason'],$assignment['finish_action'],$assignment['finish_reason']);
   $this->store->put('assignment',(string)$state->getId(),$assignment);
   $state->setAssignee(null)->setHumanTakeover(true)->setLifecycle('open')->setNeedsResponse($state->needsResponse()||$wasQueued)->setVersion($state->getVersion()+1);$this->em->persist($state);
   $this->em->persist((new EventLog())->setConversation($state->getConversation())->setActor($actor)->setEventType('ai_reset')->setDetails(['agent'=>$assignment['name']??$assignment['agent']]));$this->em->flush();
  });
 }
 public function saveAgent(array $p): void {$key=(string)($p['key']??'');if(!preg_match('/^[a-z0-9_-]{1,80}$/',$key))$key=bin2hex(random_bytes(8));$name=trim((string)($p['name']??''));if(!$name||mb_strlen($name)>100)throw new InboxException('mautic.inbox.ai.document_invalid');$this->store->put('agent',$key,['name'=>$name,'profile'=>in_array($p['profile']??'', ['macro-support','macro-sports'],true)?$p['profile']:'macro-support','enabled'=>!empty($p['enabled']),'limit'=>0,'documents'=>array_values(array_filter((array)($p['documents']??[]),'is_string')),'permissions'=>array_values(array_filter((array)($p['permissions']??[]),'is_string'))],(int)($p['revision']??0));}
}
