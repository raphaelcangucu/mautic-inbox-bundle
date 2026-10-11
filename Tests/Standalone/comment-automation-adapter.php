<?php

declare(strict_types=1);

// In-memory persistence doubles only. No kernel, PDO, connection string or Graph network client.
namespace Doctrine\ORM {interface EntityManagerInterface {}}
namespace Mautic\CoreBundle\Entity {class CommonEntity {}}
namespace Mautic\UserBundle\Entity {class User {public function getId(): int{return 42;}}}
namespace MauticPlugin\MauticMetaBundle\Infrastructure {interface MetaGraphClientInterface {}}
namespace MauticPlugin\MauticMetaBundle\Application\Facebook {class PageConnectionResolver {public function resolve($asset): object{return $asset;}}}
namespace MauticPlugin\MauticMetaBundle\Entity {
    class MetaAsset {public function __construct(private int $id=4){}public function getId():int{return $this->id;}public function getExternalId():string{return 'business';}public function getConnection():object{return $this;}public function getStatus():string{return 'active';}public function isPublished():bool{return true;}public function getSettings():array{return [];}}
    class MetaConversation {public function __construct(private int $id,private MetaAsset $asset,private string $recipient,private string $channel='instagram'){}public function getId():int{return $this->id;}public function getAsset():MetaAsset{return $this->asset;}public function getRecipient():string{return $this->recipient;}public function getChannel():string{return $this->channel;}}
    class MetaMessage {public function __construct(private int $id,public MetaConversation $conversation,public array $payload,public string $direction='inbound'){}public function getId():int{return $this->id;}public function getPayload():array{return $this->payload;}public function getConversation():MetaConversation{return $this->conversation;}public function getMessageType():string{return 'comment';}}
    class MetaOutboundJob {public function __construct(private int $id,private MetaAsset $asset,private string $operation,private array $payload,private string $status='pending'){}public function getId():int{return $this->id;}public function getAsset():MetaAsset{return $this->asset;}public function getOperation():string{return $this->operation;}public function getPayload():array{return $this->payload;}public function getStatus():string{return $this->status;}}
}
namespace MauticPlugin\MauticInboxBundle\Integration {class MetaInboxIntegration {public function runHumanTransition($state,callable $operation):mixed{return $operation();}}}
namespace MauticPlugin\MauticInboxBundle\Application\Ai {class AiService {public function assets():array{return [['id'=>4,'channel'=>'instagram','name'=>'QA'],['id'=>11,'channel'=>'facebook','name'=>'QA FB']];}}}
namespace MauticPlugin\MauticInboxBundle\Application {class ReplyAvailability {public function reason($state,?string $mode=null):?string{if($mode!=='public')throw new \RuntimeException('Private reply attempted');return null;}}}
namespace MauticPlugin\MauticMetaBundle\Application\Queue {
    class OutboundQueue {public array $jobs=[];public function enqueue($asset,string $operation,array $payload,$contact,int $maxAttempts,string $key):\MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob{
        if($maxAttempts!==1 || $contact!==null)throw new \RuntimeException('Retry/CRM scope changed');
        return $this->jobs[$key]??=new \MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob(count($this->jobs)+1,$asset,$operation,$payload);
    }}
}
namespace {
    $root=dirname(__DIR__,2);
    foreach(['Application/InboxException.php','Entity/AiRecord.php','Entity/ConversationState.php','Entity/CommentContext.php','Entity/EventLog.php','Application/Ai/AiStore.php','Application/Ai/PiClient.php','Application/Ai/InternalAgentPolicy.php','Application/Ai/CommentAutomationPolicy.php','Application/Ai/CommentAutomationExecutor.php','Application/Mobile/ModerationStore.php','Application/Mobile/PublicationContext.php','Application/Ai/CommentAutomationContext.php','Application/Ai/CommentAutomationQueueGuard.php','Application/Ai/CommentAutomation.php'] as $path)require $root.'/'.$path;
    use MauticPlugin\MauticInboxBundle\Application\Ai\{AiStore,PiClient,AiService,CommentAutomation,CommentAutomationContext,CommentAutomationExecutor,CommentAutomationQueueGuard};
    use MauticPlugin\MauticInboxBundle\Application\Mobile\{ModerationStore,PublicationContext};
    use MauticPlugin\MauticInboxBundle\Entity\{AiRecord,ConversationState,CommentContext,EventLog};
    use MauticPlugin\MauticMetaBundle\Entity\{MetaMessage,MetaAsset,MetaConversation};

    final class MemoryRepository {
        public function __construct(private MemoryEm $em,private string $class){}
        public function findOneBy(array $criteria,array $order=[]):?object{return $this->findBy($criteria,$order,1)[0]??null;}
        public function findBy(array $criteria,array $order=[],?int $limit=null,int $offset=0):array{
            $rows=array_values(array_filter($this->em->rows[$this->class]??[],function($row)use($criteria){foreach($criteria as $key=>$value){$actual=match($key){'kind'=>$row->getKind(),'recordKey'=>$row->getRecordKey(),'conversation'=>$row->getConversation(),'publicConversation'=>$row->getPublicConversation(),'commentId'=>$row->getCommentId(),'direction'=>$row->direction,'messageType'=>$row->getMessageType(),default=>throw new RuntimeException('Unexpected criteria '.$key)};if($actual!==$value)return false;}return true;}));
            if(isset($order['id']))usort($rows,fn($a,$b)=>($order['id']==='DESC'?-1:1)*($a->getId()<=>$b->getId()));
            return array_slice($rows,$offset,$limit);
        }
    }
    final class MemoryQuery {
        private array $parameters=[];private int $limit=10;
        public function __construct(private MemoryEm $em){}
        public function select($v):self{return $this;}public function from($a,$b):self{return $this;}public function join($a,$b):self{return $this;}public function where($v):self{return $this;}public function orderBy($a,$b):self{return $this;}
        public function setParameter($key,$value):self{$this->parameters[$key]=$value;return $this;}public function setMaxResults($v):self{$this->limit=$v;return $this;}public function getQuery():self{return $this;}
        public function getResult():array{return array_slice(array_values(array_filter($this->em->rows[CommentContext::class]??[],fn($c)=>$c->getMessage()->getId()>$this->parameters['cursor'] && in_array($c->getPublicConversation()->getAsset()->getId(),$this->parameters['assets'],true))),0,$this->limit);}
    }
    final class MemoryEm implements \Doctrine\ORM\EntityManagerInterface {
        public array $rows=[];public array $refreshedDocuments=[];private int $sequence=1000;
        public function getRepository(string $class):MemoryRepository{return new MemoryRepository($this,$class);}
        public function persist(object $row):void{
            $class=$row::class;foreach($this->rows[$class]??[] as $existing)if($existing===$row)return;
            if($row instanceof AiRecord){$id=new ReflectionProperty(AiRecord::class,'id');$id->setValue($row,++$this->sequence);}
            $this->rows[$class][]=$row;
        }
        public function refresh(object $row):void{if($row instanceof AiRecord && $row->getKind()==='document')$this->refreshedDocuments[]=$row->getRecordKey();}public function flush():void{}
        public function find(string $class,int $id):?object{foreach($this->rows[$class]??[] as $row)if($row->getId()===$id)return $row;return null;}
        public function createQueryBuilder():MemoryQuery{return new MemoryQuery($this);}
        public function getConnection():object{return new class {public function fetchOne(string $sql):int{if(!str_contains($sql,'GET_LOCK')&&!str_contains($sql,'RELEASE_LOCK'))throw new RuntimeException('Database SQL forbidden in this suite');return 1;}};}
    }
    final class FixturePi extends PiClient {public array $result=[];public $before=null;public int $calls=0;public function call(string $action,array $input=[]):array{
        if($action!=='comment' || array_keys($input['comment'])!==['text','caption'] || isset($input['funnel']))throw new RuntimeException('Private/classifier scope changed');
        ++$this->calls;if($this->before){$callback=$this->before;$this->before=null;$callback();}return $this->result;
    }}
    final class FixtureGraph implements \MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface {public function get($connection,$id,$parameters):array{return ['id'=>$id,'caption'=>'Conheça nosso atendimento.','message'=>'Conheça nosso atendimento.'];}}

    $checks=0;function verify(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);++$checks;}
    $directory=sys_get_temp_dir().'/mautic-comment-testing-'.bin2hex(random_bytes(6));mkdir($directory.'/app',0700,true);
    $em=new MemoryEm();$store=new AiStore($em);$moderation=new ModerationStore($directory.'/app');$context=new CommentAutomationContext($em,$store,$moderation);$pi=new FixturePi();$queue=new \MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue();
    $publications=new PublicationContext(new FixtureGraph(),new \MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver(),$directory.'/app');
    $service=new CommentAutomation($store,$em,new AiService(),$pi,$context,new CommentAutomationExecutor(),$publications,$moderation,new \MauticPlugin\MauticInboxBundle\Application\ReplyAvailability(),new \MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration(),$queue);
    $guard=new CommentAutomationQueueGuard($store,$context,$em);
    $store->put('config','global',['enabled'=>true,'permissions'=>['4:comment','11:comment'],'model'=>'fixture']);
    $store->put('health','pi',['validated'=>true]);
    $store->put('agent','qa',['name'=>'QA','enabled'=>true,'permissions'=>['4:comment','11:comment'],'documents'=>[]]);
    $store->saveDocument(['key'=>'rule','revision'=>0,'name'=>'rule.md','body'=>'Use somente o contexto público.','scope'=>'agent','publish'=>true],42);
    $config=['key'=>'qa','revision'=>0,'mode'=>'off','asset_ids'=>[4,11],'publication_ids'=>[],'document_key'=>'rule','document_version'=>1,'mark_spam_enabled'=>true,'public_reply_enabled'=>true,'classify_limit'=>100,'reply_limit'=>20,'min_interval'=>0];
    function incoming(MemoryEm $em,int $id,string $body,string $channel='instagram'):ConversationState{
        $asset=new MetaAsset($channel==='facebook'?11:4);$em->persist($asset);$conversation=new MetaConversation($id,$asset,'comment:'.(100000+$id),$channel);
        $message=new MetaMessage($id,$conversation,['text'=>$body]);$em->persist($message);
        $state=(new ConversationState())->setConversation($conversation)->setLastInboundMessageId($id);(new ReflectionProperty(ConversationState::class,'id'))->setValue($state,$id);$em->persist($state);
        $cc=(new CommentContext())->setMessage($message)->setPublicConversation($conversation)->setCommentId((string)(100000+$id))->setMediaId('987654321')->setAccountId('business')->setParticipantId('visitor');$em->persist($cc);return $state;
    }
    try {
        $historical=incoming($em,100,'Earlier comment');
        $off=$service->saveConfig($config,42);verify($off['mode']==='off','Configuration persists disabled');
        verify($service->work()['processed']===0 && $pi->calls===0,'Off adapter never calls Pi');
        $config=array_replace($config,['revision'=>$off['revision'],'mode'=>'automatic']);$active=$service->saveConfig($config,42);
        verify(count($context->documents($active,$store->get('agent','qa')))===1,'Comment rule is included only by explicit automation selection');
        verify($store->context($store->get('agent','qa'))===[],'Normal agent context remains unchanged');
        verify($active['cursor']===100,'Enabling initializes the server watermark and excludes history');
        $spam=incoming($em,101,'Visite nossa loja e compre agora!');
        $pi->result=['decision'=>'spam','confidence'=>0.99,'safe'=>true,'reason'=>'Third party advertisement','evidence'=>'Visite nossa loja','text'=>''];
        verify($service->work()['processed']===1,'New comment classified');
        $flags=$moderation->flags(['id'=>101,'channel'=>'instagram','asset'=>['id'=>4],'recipient'=>'comment:100101']);
        verify($flags['spam']===true && count($queue->jobs)===0,'Real local moderation store marks only spam without queuing reply');
        verify(!$spam->needsResponse(),'Spam leaves the needs-response queue');
        verify($service->work()['processed']===0 && $pi->calls===1,'Watermark and stable run deduplicate webhook delivery');
        $legitimate=incoming($em,102,'Como falo com a equipe?');
        $pi->result=['decision'=>'legitimate','confidence'=>0.98,'safe'=>true,'reason'=>'Support question','evidence'=>'','text'=>'Use o canal de atendimento indicado na publicação.'];
        verify($service->work()['processed']===1 && count($queue->jobs)===1,'Legitimate comment passes adapter and queue');
        $job=array_values($queue->jobs)[0];verify($job->getOperation()==='instagram_public_reply' && $job->getPayload()['recipient']==='100102','Instagram uses public reply to exact comment');
        $deliveries=0;$guard->execute($legitimate,$job,function()use(&$deliveries){++$deliveries;return 'remote-id';});
        verify($deliveries===1 && !$legitimate->needsResponse(),'Queue guard confirms a single public send');
        try{$guard->execute($legitimate,$job,function()use(&$deliveries){++$deliveries;});throw new RuntimeException('Repeat accepted');}catch(DomainException){}
        verify($deliveries===1,'Same job cannot send twice');
        $facebook=incoming($em,103,'Como falo com a equipe?','facebook');$service->work();$fbJob=array_values($queue->jobs)[1];verify($fbJob->getOperation()==='facebook_public_reply','Facebook uses public reply');
        $disabled=$service->saveConfig(array_replace($config,['revision'=>$active['revision'],'mode'=>'off']),42);
        try{$guard->execute($facebook,$fbJob,function()use(&$deliveries){++$deliveries;});throw new RuntimeException('Disabled job accepted');}catch(DomainException){}
        verify($deliveries===1,'Turning off prevents queued Facebook reply');
        $review=$service->saveConfig(array_replace($config,['revision'=>$disabled['revision'],'mode'=>'review']),42);
        $reviewState=incoming($em,104,'Visite nossa loja e compre agora!');$pi->result=['decision'=>'spam','confidence'=>0.99,'safe'=>true,'reason'=>'Third party advertisement','evidence'=>'Visite nossa loja','text'=>''];$service->work();
        $proposal=$service->runs()['items'][0];verify($proposal['status']==='review','Review creates durable proposal');
        verify(!$moderation->flags(['id'=>104,'channel'=>'instagram','asset'=>['id'=>4],'recipient'=>'comment:100104'])['spam'],'Review does not mark before approval');
        $service->review(['run_key'=>$proposal['key'],'revision'=>$proposal['revision'],'decision'=>'approve'],new \Mautic\UserBundle\Entity\User());
        verify($moderation->flags(['id'=>104,'channel'=>'instagram','asset'=>['id'=>4],'recipient'=>'comment:100104'])['spam'],'Human approval marks through actual moderation adapter');
        $active=$service->saveConfig(array_replace($config,['revision'=>$review['revision'],'mode'=>'automatic']),42);
        incoming($em,105,'Visite nossa loja e compre agora!');
        $pi->before=fn()=>$service->saveConfig(array_replace($config,['revision'=>$active['revision'],'mode'=>'off']),42);$service->work();
        verify(!$moderation->flags(['id'=>105,'channel'=>'instagram','asset'=>['id'=>4],'recipient'=>'comment:100105'])['spam'],'Disabling during real adapter generation cancels side effects');
        verify($service->runs()['items'][0]['status']==='cancelled','Cancelled execution is audit-visible');
        $latest=$store->find('comment_config','qa');
        try{$service->saveConfig(array_replace($config,['revision'=>$latest->getRevision(),'mode'=>'automatic','document_version'=>99]),42);throw new RuntimeException('Unknown rule version accepted');}catch(\MauticPlugin\MauticInboxBundle\Application\InboxException){}
        $active=$service->saveConfig(array_replace($config,['revision'=>$latest->getRevision(),'mode'=>'automatic']),42);
        $global=$store->saveDocument(['key'=>'global-context','revision'=>0,'name'=>'global.md','body'=>'Public policy one','scope'=>'global','publish'=>true],42);
        incoming($em,106,'Visite nossa loja e compre agora!');
        $pi->before=function()use($store,$global,$em){$store->saveDocument(['key'=>'global-context','revision'=>$global['revision'],'name'=>'global.md','body'=>'Public policy two','scope'=>'global','publish'=>true],42);$em->refreshedDocuments=[];};$service->work();
        verify($service->runs()['items'][0]['status']==='cancelled','Publishing a global context document during generation cancels the action');
        verify(in_array('global-context',$em->refreshedDocuments,true),'Post-generation reads refresh global documents instead of Doctrine snapshots');
        verify(count($em->rows[EventLog::class]??[])===3,'Spam, sent reply and reviewed spam leave audit events');
        $key=$job->getPayload()['_comment_automation'];$interrupted=$store->get('comment_run',$key);$interrupted['status']='sending';$store->put('comment_run',$key,$interrupted);
        $blocked=new \MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob($job->getId(),$job->getAsset(),$job->getOperation(),$job->getPayload(),'blocked');$guard->failed($blocked);
        verify($store->get('comment_run',$key)['status']==='uncertain','Crash during remote delivery stays uncertain even when retry is blocked');
        echo "PASS $checks production adapter checks with memory persistence and disposable local moderation files; no database or network\n";
    } finally {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($directory);
    }
}
