<?php
// In-memory adapters and temporary files only; no Mautic kernel or database.
namespace Doctrine\ORM {interface EntityManagerInterface {public function persist($entity);public function flush();}}
namespace Mautic\UserBundle\Entity {class User {public function getId(){return 1;}}}
namespace MauticPlugin\MauticInboxBundle\Entity {
 class TestState {public int $version=1;public bool $needs=true;public function getId(){return 8;}public function getVersion(){return $this->version;}public function setVersion($v){$this->version=$v;return $this;}public function setNeedsResponse($v){$this->needs=$v;return $this;}public function getLifecycle(){return 'open';}public function getConversation(){return new \stdClass();}}
 class ConversationStateRepository {public TestState $state;public function __construct(){$this->state=new TestState();}public function find($id){return $id===8?$this->state:null;}}
 class EventLog {public function setConversation($v){return $this;}public function setEventType($v){return $this;}public function setDetails($v){return $this;}}
}
namespace MauticPlugin\MauticInboxBundle\Security {class ConversationAccess {public bool $allowed=true;public function assertView($state,$user){if(!$this->allowed)throw new \MauticPlugin\MauticInboxBundle\Application\InboxException('denied',403);}public function granted($user,$permission){return $this->allowed;}}}
namespace MauticPlugin\MauticInboxBundle\Application {class InboxQuery {public function summary($state,$user=null){return ['id'=>8,'contact_name'=>'Test author','channel'=>'instagram','asset'=>['id'=>3,'name'=>'Test account'],'recipient'=>'test-author'];}public function detail($state,$user){return ['origins'=>[['id'=>'post']]];}}}
namespace MauticPlugin\MauticInboxBundle\Application\Ai {class CommentAutomationContext {
 public array $source=['state_id'=>8,'state_version'=>1,'asset_id'=>3,'channel'=>'instagram','recipient'=>'comment:10001','comment_id'=>'10001','publication_id'=>'20001','inbound_id'=>99,'body'=>'Visit my shop @ads','asset_active'=>true,'origin'=>['caption'=>'Sports post']];
 public function read($state){return array_replace($this->source,['state_version'=>$state->getVersion()]);}
}}
namespace MauticPlugin\MauticInboxBundle\Application\Mobile {class PublicationContext {public int $calls=0;public bool $confirmed=true;public function hideInstagram($state,$origins,$hide){$this->calls++;if(!$this->confirmed)throw new \DomainException('moderation_not_confirmed');}}}
namespace MauticPlugin\MauticInboxBundle\Integration {class MetaInboxIntegration {public int $locks=0;public function runHumanTransition($state,$call){$this->locks++;return $call();}}}
namespace {
require __DIR__.'/../../Application/InboxException.php';
require __DIR__.'/../../Application/Mobile/AssistantActionPolicy.php';
require __DIR__.'/../../Application/Mobile/AssistantModerationPolicy.php';
require __DIR__.'/../../Application/Mobile/AssistantCommentModeration.php';
require __DIR__.'/../../Application/Mobile/ModerationStore.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\{AssistantActionPolicy as Action,AssistantModerationPolicy as Policy,AssistantCommentModeration as Service,ModerationStore};
use MauticPlugin\MauticInboxBundle\Application\InboxException;
function check($value,$name){if(!$value)throw new \RuntimeException($name);}
function denied($call,$status){try{$call();}catch(InboxException $e){check($e->httpStatus===$status,'wrong denial');return;}throw new \RuntimeException('expected denial');}
$states=new \MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository();
$em=new class implements \Doctrine\ORM\EntityManagerInterface {public int $writes=0;public function persist($entity){}public function flush(){$this->writes++;}};
$access=new \MauticPlugin\MauticInboxBundle\Security\ConversationAccess();$inbox=new \MauticPlugin\MauticInboxBundle\Application\InboxQuery();$context=new \MauticPlugin\MauticInboxBundle\Application\Ai\CommentAutomationContext();$network=new \MauticPlugin\MauticInboxBundle\Application\Mobile\PublicationContext();$integration=new \MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration();
$root=sys_get_temp_dir().'/assistant-moderation-test-'.bin2hex(random_bytes(6));mkdir($root,0700);$flags=new ModerationStore($root);$user=new \Mautic\UserBundle\Entity\User();
$service=new Service($states,$em,$access,$inbox,$flags,$context,$network,$integration);
$action=Action::normalize(['tool'=>'inbox_moderate','id'=>8,'operation'=>'spam','reason'=>'Third-party advertisement','token'=>'ignored']);check(!isset($action['token']),'untrusted fields removed');
$preview=$service->preview($action,$user);check($em->writes===0&&$network->calls===0,'preview is read only');check($preview['fields']['scope']==='inbox'&&$preview['fields']['account']==='Test account','explicit local scope and account');
$context->source['body']='Legitimate question';denied(fn()=>$service->apply($action+['snapshot'=>$preview['snapshot']],$user),409);check($em->writes===0,'changed comment cannot execute');$context->source['body']='Visit my shop @ads';
$access->allowed=false;denied(fn()=>$service->apply($action+['snapshot'=>$preview['snapshot']],$user),403);$access->allowed=true;
$result=$service->apply($action+['snapshot'=>$preview['snapshot']],$user);check($result['moderation']['spam']&&$result['scope']==='inbox'&&$em->writes===1&&$network->calls===0&&!$states->state->needs,'spam uses existing local adapter, no network delete');
denied(fn()=>$service->apply($action+['snapshot'=>$preview['snapshot']],$user),409);
$hide=Action::normalize(['tool'=>'inbox_moderate','id'=>8,'operation'=>'hide','reason'=>'Requested hide']);$preview=$service->preview($hide,$user);$network->confirmed=false;
try{$service->apply($hide+['snapshot'=>$preview['snapshot']],$user);throw new \RuntimeException('expected unconfirmed network');}catch(\DomainException){}
check(!$flags->flags($inbox->summary($states->state))['hidden'],'failed remote hide is never recorded as hidden');$network->confirmed=true;
$result=$service->apply($hide+['snapshot'=>$preview['snapshot']],$user);check($result['moderation']['hidden']&&$result['scope']==='instagram','remote-confirmed hide');
$context->source['channel']='facebook';denied(fn()=>$service->preview($hide,$user),403);
$context->source['channel']='whatsapp';denied(fn()=>$service->preview($action,$user),403);
$context->source['channel']='instagram';$context->source['own_comment']=true;denied(fn()=>$service->preview($action,$user),403);
foreach(['delete','delete_profile','remove_user','send'] as $operation)denied(fn()=>Action::normalize(['tool'=>'inbox_moderate','id'=>8,'operation'=>$operation,'reason'=>'x']),422);
check(Policy::clientTools(['inbox_moderate','mautic_reply_inbox'],[])===['mautic_reply_inbox'],'old app cannot receive unknown proposals');check(Policy::clientTools(['inbox_moderate'],['supported_actions'=>['inbox_moderate']])===['inbox_moderate'],'new client opt-in never grants server permissions');
unlink($root.'/var/inbox-mobile/moderation.json');rmdir($root.'/var/inbox-mobile');rmdir($root.'/var');rmdir($root);
echo "Assistant moderation: all checks passed (memory adapters, no database)\n";
}
