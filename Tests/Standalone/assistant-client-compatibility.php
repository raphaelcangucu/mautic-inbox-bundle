<?php
// In-memory adapters only: no kernel, database, credentials or model process.
namespace Doctrine\ORM { interface EntityManagerInterface { public function refresh($entity); } }
namespace Mautic\UserBundle\Entity { class User { public function getId(){return 1;} public function getRole(){return null;} public function isPublished(){return true;} } }
namespace MauticPlugin\MauticInboxBundle\Security { class ConversationAccess { public bool $allowed=true; public function canViewInbox($user){return $this->allowed;} public function granted($user,$permission){return $this->allowed;} } }
namespace MauticPlugin\MauticInboxBundle\Application\Ai { class AiStore {
 public bool $enabled=true;
 public function all($type){return [['key'=>'internal']];}
 public function get($type,$key){return ['key'=>'internal','name'=>'Assistant','audience'=>'internal','enabled'=>$this->enabled,'mcp_connection'=>'current_mautic','mcp_tools'=>['inbox_comments','inbox_moderate']];}
 public function context($agent){return [];}
} }
namespace {
require __DIR__.'/../../Application/InboxException.php';
require __DIR__.'/../../Application/Ai/InternalAgentPolicy.php';
require __DIR__.'/../../Application/Mobile/AssistantModerationPolicy.php';
require __DIR__.'/../../Application/Mobile/AssistantCommentBatchPolicy.php';
require __DIR__.'/../../Application/Mobile/OperatorAssistant.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\OperatorAssistant;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
function check($v,$m){if(!$v)throw new \RuntimeException($m);}
$class=new \ReflectionClass(OperatorAssistant::class);$assistant=$class->newInstanceWithoutConstructor();
$access=new \MauticPlugin\MauticInboxBundle\Security\ConversationAccess();
$store=new \MauticPlugin\MauticInboxBundle\Application\Ai\AiStore();
$em=new class implements \Doctrine\ORM\EntityManagerInterface {public function refresh($entity){}};
foreach(['access'=>$access,'store'=>$store,'em'=>$em] as $name=>$value){$p=$class->getProperty($name);$p->setValue($assistant,$value);}
$question=['agent_key'=>'internal','message'=>'Já foi liberado mais ações. Pode tratar todos comentarios em aberto'];
$response=$assistant->reply($question,new \Mautic\UserBundle\Entity\User());
check($response['proposals']===[]&&$response['comment_classifications']===[]&&$response['comment_page']===null,'legacy client receives no unsupported cards or misleading analysis');
check(substr_count($response['text'],'Atualize')===1&&str_contains($response['text'],'Nenhum comentário foi alterado'),'one upgrade notice, honest action state');
// Uninitialized Pi/Inbox/action dependencies prove this path uses no model or comment reads/writes.
$access->allowed=false;
try{$assistant->reply($question,new \Mautic\UserBundle\Entity\User());throw new \RuntimeException('access bypassed');}catch(InboxException $e){check($e->httpStatus===403,'compatibility never bypasses permissions');}
$access->allowed=true;$store->enabled=false;
try{$assistant->reply($question,new \Mautic\UserBundle\Entity\User());throw new \RuntimeException('disabled agent bypassed');}catch(InboxException $e){check($e->httpStatus===403,'disabled agent still denied');}
echo "Assistant client compatibility: all checks passed (no model or database)\n";
}
