<?php
/** Run only in an installed disposable Mautic; never against production. */
declare(strict_types=1);
$project=getenv('MAUTIC_TEST_PROJECT_DIR') ?: '/var/www/html';
require $project.'/vendor/autoload.php';
$_SERVER['MAUTIC_TABLE_PREFIX']='';
use Mautic\UserBundle\Entity\{Role,User};
use MauticPlugin\MauticInboxBundle\Entity\{ConversationState,Note};
use MauticPlugin\MauticMetaBundle\Entity\{MetaConnection,MetaAsset,MetaConversation,MetaMessage};
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticInboxBundle\Application\{InboxQuery,ConversationActions,InboxException};
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
$kernel=new AppKernel('prod',false);$kernel->boot();$services=$kernel->getContainer();$em=$services->get('doctrine.orm.entity_manager');$db=$em->getConnection();
$actual=$db->fetchOne('SELECT DATABASE()');$allow=getenv('MAUTIC_TEST_DATABASE_ALLOW_DESTRUCTIVE');
if ($actual!==$allow || !preg_match('/(?:test|testing|ci|scratch|tmp)/i',(string)$actual) || ($db->getParams()['host']??'')!=='review_db') throw new RuntimeException('Actual test kernel selected an unproven database. STOP.');
$checks=[];
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks[]=$label;}
function denied(callable $call,string $label):void {try{$call();}catch(InboxException $e){check($e->httpStatus===404,$label);return;}throw new RuntimeException('Authorized forbidden action: '.$label);}
$permission=$services->get('mautic.security');
$tag='scope-test-'.bin2hex(random_bytes(4));$users=[];
foreach(['reviewer'=>1,'operator'=>3,'supervisor'=>4] as $name=>$scope){
 $role=(new Role())->setName($tag.'-'.$name)->setIsAdmin(false);
 foreach($permission->generatePermissions(['inbox:conversations'=>['view','edit','create'],'meta:messages'=>['view','edit','create'],'inbox:scope'=>[$scope===1?'own':($scope===3?'own':'all')]]) as $p){$p->setRole($role);$role->addPermission($p);$em->persist($p);}
 if($scope===3){foreach($role->getPermissions() as $p)if($p->getBundle()==='inbox'&&$p->getName()==='scope')$p->setBitwise(3);}
 $em->persist($role);
 $user=(new User())->setUsername($tag.'-'.$name)->setEmail($tag.'-'.$name.'@test.invalid')->setFirstName('Test')->setLastName($name)->setPassword(password_hash(bin2hex(random_bytes(24)),PASSWORD_BCRYPT))->setRole($role)->setIsPublished(true);$em->persist($user);$users[$name]=$user;
}
$em->flush();$access=$services->get(ConversationAccess::class);
$connection=(new MetaConnection())->setName($tag)->setAppId($tag)->setEncryptedAppSecret('')->setEncryptedAccessToken('')->setEncryptedVerifyToken('')->setStatus('inactive');$em->persist($connection);
$asset=(new MetaAsset())->setConnection($connection)->setExternalId($tag)->setName('Local fictional QA')->setType(AssetType::FacebookPage)->setStatus('inactive')->setIsPublished(true);$em->persist($asset);
$states=[];
foreach(['reviewer-own','operator-own','unassigned-waiting','unassigned-idle','unassigned-snoozed','unassigned-resolved','foreign-needs'] as $name){
 $c=(new MetaConversation())->setAsset($asset)->setChannel('facebook')->setRecipient($tag.'-'.$name)->setUnreadCount(1)->setLastInboundAt(new DateTimeImmutable());$em->persist($c);
 $assignee=match($name){'reviewer-own'=>$users['reviewer'],'operator-own'=>$users['operator'],'foreign-needs'=>$users['supervisor'],default=>null};
 $state=(new ConversationState())->setConversation($c)->setAssignee($assignee)->setNeedsResponse($name!=='unassigned-idle')->setLifecycle(match($name){'unassigned-snoozed'=>'snoozed','unassigned-resolved'=>'resolved',default=>'open'});$em->persist($state);$states[$name]=$state;
 $m=(new MetaMessage())->setAsset($asset)->setConversation($c)->setChannel('facebook')->setDirection('inbound')->setMessageType('text')->setRecipient($c->getRecipient())->setPayload(['text'=>'Fictional '.$name])->setStatus('received');$em->persist($m);
}
$em->flush();
$helper=$services->get('mautic.helper.user');$tokens=(new ReflectionProperty($helper,'tokenStorage'))->getValue($helper);$tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($users['reviewer'],'main',['ROLE_USER']));
$q=$services->get(InboxQuery::class);$actions=$services->get(ConversationActions::class);
$reviewList=$q->conversations($users['reviewer'],['queue'=>'all','lifecycle'=>'all','limit'=>50,'search'=>$tag]);$reviewIds=array_column($reviewList['items'],'id');
check(count($q->conversations($users['reviewer'],['queue'=>'all','lifecycle'=>'all','limit'=>1,'search'=>$tag])['items'])===1,'Scope applies before pagination');
check($reviewIds===[$states['reviewer-own']->getId()],'Reviewer list contains only own');
check($reviewList['counts']===['mine'=>1,'unassigned'=>0,'all'=>1],'Reviewer counts exclude waiting/foreign');
$opList=$q->conversations($users['operator'],['queue'=>'all','lifecycle'=>'all','limit'=>50,'search'=>$tag]);$opIds=array_column($opList['items'],'id');sort($opIds);$expected=[$states['operator-own']->getId(),$states['unassigned-waiting']->getId()];sort($expected);check($opIds===$expected,'Operator sees own + unassigned open waiting only');
check(count($q->conversations($users['supervisor'],['queue'=>'all','lifecycle'=>'all','limit'=>50,'search'=>$tag])['items'])>=7,'Supervisor all scope');
check($access->canView($states['reviewer-own'],$users['reviewer']),'Own assigned allowed');
check(!$access->canView($states['unassigned-waiting'],$users['reviewer']),'Own-only cannot claim waiting queue');
foreach(['operator-own','foreign-needs','unassigned-waiting'] as $name){$state=$states[$name];
 denied(fn()=>$q->detail($state,$users['reviewer']),'Detail denies '.$name);
 denied(fn()=>$q->timeline($state,null,40,$users['reviewer']),'History denies '.$name);
 denied(fn()=>$actions->note($state,$users['reviewer'],'must not save'),'Note denies '.$name);
 denied(fn()=>$actions->saveDraft($state,$users['reviewer'],'reply','must not save'),'Draft denies '.$name);
 denied(fn()=>$actions->take($state,$users['reviewer'],$state->getVersion()),'Take denies '.$name);
 denied(fn()=>$q->poll($users['reviewer'],(new DateTimeImmutable('-1 hour'))->format(DATE_ATOM),$state,0),'Poll selected denies '.$name);
}
$poll=$q->poll($users['reviewer'],(new DateTimeImmutable('-1 hour'))->format(DATE_ATOM),null,0);
check(array_column($poll['conversations'],'id')===$reviewIds,'Poll list scoped');
check(array_unique(array_column($poll['notifications'],'state_id'))===$reviewIds,'Notification feed scoped');
check(count($q->timeline($states['reviewer-own'],null,40,$users['reviewer'])['items'])===1,'Own history readable');
$note=$actions->note($states['reviewer-own'],$users['reviewer'],'Fictional private QA note');check($note->getId()>0,'Own note write allowed');
check(count($em->getRepository(Note::class)->findBy(['conversation'=>$states['reviewer-own']->getConversation()]))===1,'Denied notes did not mutate');
$assistant=$services->get(MauticPlugin\MauticInboxBundle\Application\Mobile\OperatorAssistant::class);
denied(fn()=>$assistant->reply(['message'=>'read customer','conversation_id'=>$states['foreign-needs']->getId()],$users['reviewer']),'Assistant denies selected foreign context before Pi');
// Authorize test grants through the same PKCE store, never through copied MCP tokens.
$store=$services->get(MauticPlugin\MauticInboxBundle\Application\Mobile\SessionStore::class);$verifier=str_repeat('x',64);$redirect='mauticinbox://auth';$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');$code=$store->authorize($users['reviewer']->getId(),hash('sha256',$users['reviewer']->getPassword()),$challenge,$redirect);$grant=$store->exchange($code,$verifier,$redirect);
$export=['database'=>$actual,'user_id'=>$users['reviewer']->getId(),'own_state'=>$states['reviewer-own']->getId(),'foreign_state'=>$states['foreign-needs']->getId(),'waiting_state'=>$states['unassigned-waiting']->getId(),'access_token'=>$grant['access_token']];
file_put_contents('/tmp/operator-scope-http.json',json_encode($export,JSON_THROW_ON_ERROR));chmod('/tmp/operator-scope-http.json',0600);
echo json_encode(['selectedDatabase'=>$actual,'checks'=>$checks,'passed'=>count($checks),'externalMessagesSent'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
