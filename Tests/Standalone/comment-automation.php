<?php

declare(strict_types=1);

// No Mautic bootstrap, database, Graph request or credential is loaded by this suite.
require dirname(__DIR__,2).'/Application/Ai/CommentAutomationPolicy.php';
require dirname(__DIR__,2).'/Application/Ai/CommentAutomationExecutor.php';
require dirname(__DIR__,2).'/Application/Ai/CommentAutomationQueueGuard.php';
use MauticPlugin\MauticInboxBundle\Application\Ai\{CommentAutomationPolicy as Policy, CommentAutomationExecutor as Executor, CommentAutomationQueueGuard as Guard};

$checks=0;
function check(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);++$checks;}
$config=['key'=>'test-agent','revision'=>2,'mode'=>'automatic','asset_ids'=>[4],'publication_ids'=>[],
    'document_key'=>'rule','document_version'=>1,'mark_spam_enabled'=>true,'public_reply_enabled'=>true];
$source=['asset_id'=>4,'channel'=>'instagram','recipient'=>'comment:123456789','comment_id'=>'123456789','publication_id'=>'987654321',
    'state_id'=>1,'inbound_id'=>2,'state_version'=>1,'lifecycle'=>'open','needs_response'=>true,'asset_active'=>true,'body'=>'Visite nossa loja e compre aqui agora!',
    'caption'=>'Conheça o atendimento da empresa.','allowed_links'=>['https://example.test/support']];
$spam=['decision'=>'spam','confidence'=>0.97,'safe'=>true,'reason'=>'Unsolicited unrelated advertisement','evidence'=>'Visite nossa loja','text'=>''];
$legitimate=['decision'=>'legitimate','confidence'=>0.94,'safe'=>true,'reason'=>'Support question related to the post','evidence'=>'','text'=>'Você pode falar com nossa equipe pelo canal de atendimento indicado na publicação.'];
$engine=new Executor();
$generated=$marked=$queued=0;$saved=[];
$save=function($run)use(&$saved){$saved[]=$run;};
$mark=function($run,$fresh)use(&$marked){++$marked;};
$reply=function($run,$fresh)use(&$queued){++$queued;return 77;};
$current=fn()=>$source;
$generate=function()use(&$generated,$spam){++$generated;return $spam;};

check($engine->process(array_replace($config,['mode'=>'off']),$source,null,$generate,$current,$save,$mark,$reply)===null,'Off must not process');
check($generated===0 && $marked===0 && $queued===0,'Off must have zero side effects');
$review=$engine->process(array_replace($config,['mode'=>'review']),$source,null,$generate,$current,$save,$mark,$reply);
check($review['status']==='review' && $marked===0 && $queued===0,'Review must only create a proposal');
$run=$engine->process($config,$source,null,$generate,$current,$save,$mark,$reply);
check($run['status']==='spam' && $marked===1 && $queued===0,'Spam must only mark the originating comment');
check($run['comment_id']===$source['comment_id'] && $run['asset_id']===$source['asset_id'],'Spam scope must preserve account and comment');
check($engine->process($config,$source,$run,$generate,$current,$save,$mark,$reply)===null && $marked===1,'Duplicate event must not mark or generate again');
$replyRun=$engine->process($config,$source,null,fn()=>$legitimate,$current,$save,$mark,$reply);
check($replyRun['status']==='queued' && $replyRun['job']===77 && $queued===1,'Legitimate comment must enqueue a reply');
check(Policy::operation('instagram')==='instagram_public_reply' && Policy::operation('facebook')==='facebook_public_reply','Both channels must reply publicly');
check($engine->process(array_replace($config,['mark_spam_enabled'=>false]),$source,null,$generate,$current,$save,$mark,$reply)['status']==='review' && $marked===1,'Spam switch is independent');
check($engine->process(array_replace($config,['public_reply_enabled'=>false]),$source,null,fn()=>$legitimate,$current,$save,$mark,$reply)['status']==='review' && $queued===1,'Reply switch is independent');
check($engine->process($config,$source,null,$generate,fn()=>null,$save,$mark,$reply)['status']==='cancelled' && $marked===1,'Revoking configuration during generation must cancel');
check($engine->process($config,$source,null,$generate,fn()=>array_replace($source,['human'=>true]),$save,$mark,$reply)['status']==='cancelled','Human takeover during generation must cancel');
check($engine->process($config,$source,null,$generate,fn()=>array_replace($source,['body'=>'Edited comment']),$save,$mark,$reply)['status']==='cancelled','Edited comment must invalidate the generation');
check($engine->process($config,$source,null,$generate,fn()=>array_replace($source,['state_version'=>2]),$save,$mark,$reply)['status']==='cancelled','A manual reply/transition must invalidate the generation');
foreach(['human','ai_assigned','deleted','own_comment','already_replied','spam','blocked'] as $flag) check($engine->process($config,array_replace($source,[$flag=>true]),null,$generate,$current,$save,$mark,$reply)===null,'Must skip '.$flag);
check(!Policy::eligible(array_replace($source,['recipient'=>'comment:another']),$config),'Mismatched comment identity rejected');
check(!Policy::eligible($source,array_replace($config,['publication_ids'=>['111111111']])),'Publication filter enforced');
check(Policy::key(4,'same')!==Policy::key(5,'same'),'Same comment ID in two accounts must be distinct');
foreach([
    array_replace($spam,['confidence'=>0.6]), array_replace($spam,['evidence'=>'Invented evidence']),
    array_replace($spam,['safe'=>false]), array_replace($spam,['decision'=>'delete']),
    array_replace($legitimate,['text'=>'Send me your code at private@example.test']),
    array_replace($legitimate,['text'=>'Visit https://evil.test/phishing']),
    array_replace($legitimate,['text'=>'Secret Bearer abcdefghijklmnop']),
    array_replace($legitimate,['text'=>'Call +55 31 98432-0000']),
    ['decision'=>'spam'],
] as $unsafe) check(Policy::decision($unsafe,$source)['decision']==='review','Unsafe/ambiguous output must enter review');
check(Policy::decision($legitimate,array_replace($source,['caption'=>'']))['decision']==='review','Missing post context must not produce a public reply');
check(Policy::decision(array_replace($legitimate,['text'=>'Veja https://example.test/support']),$source)['decision']==='legitimate','Approved official link allowed');
$lost=$engine->process($config,$source,null,fn()=>$legitimate,$current,$save,$mark,fn()=>throw new RuntimeException('Response lost after send'));
check($lost['status']==='uncertain','Lost delivery response must not be retried');
check($engine->process($config,$source,$lost,fn()=>$legitimate,$current,$save,$mark,$reply)===null,'Uncertain run must remain deduplicated');
$failed=$engine->process($config,$source,null,fn()=>throw new RuntimeException('Model down'),$current,$save,$mark,$reply);
check($failed['status']==='failed','Model failure must not send or mark');
$throttled=$engine->process($config,$source,null,fn()=>$legitimate,$current,$save,$mark,fn()=>throw new DomainException('public_reply_unavailable_or_limit'));
check($throttled['status']==='review','Rate limit without an attempted send is a review, not an uncertain delivery');
$global=['enabled'=>true,'permissions'=>['4:comment']];$agent=['enabled'=>true,'permissions'=>['4:comment']];
check(Policy::permission($config,$global,$agent,4),'Authorized account accepted');
foreach([[array_replace($config,['mode'=>'off']),$global,$agent],[$config,array_replace($global,['enabled'=>false]),$agent],[$config,$global,array_replace($agent,['enabled'=>false])],[$config,$global,array_replace($agent,['audience'=>'internal'])],[$config,array_replace($global,['permissions'=>[]]),$agent]] as [$c,$g,$a]) check(!Policy::permission($c,$g,$a,4),'Permissions/settings must be intersected');
$job=['id'=>77,'operation'=>'instagram_public_reply','recipient'=>$source['comment_id'],'text'=>$replyRun['text'],'asset_id'=>4];
check(Guard::allowed($replyRun,$config,$source,$global,$agent,$job),'Queue guard permits exact authorized job');
foreach([
    array_replace($config,['mode'=>'off']),array_replace($config,['paused'=>true]),array_replace($config,['revision'=>3]),array_replace($config,['public_reply_enabled'=>false]),
] as $changed) check(!Guard::allowed($replyRun,$changed,$source,$global,$agent,$job),'Queued reply must respect changed settings');
foreach([['operation'=>'instagram_private_reply'],['recipient'=>'another'],['asset_id'=>5],['text'=>'Changed answer'],['id'=>78]] as $change) check(!Guard::allowed($replyRun,$config,$source,$global,$agent,array_replace($job,$change)),'Queue payload/scope cannot be changed');
check(!Guard::allowed($replyRun,array_replace($config,['mode'=>'review']),$source,$global,$agent,$job),'Review never auto-sends');
check(Guard::allowed(array_replace($replyRun,['reviewed_by'=>1]),array_replace($config,['mode'=>'review']),$source,$global,$agent,$job),'Explicit human approval allows the exact proposed reply');
check(!Guard::allowed(array_replace($replyRun,['status'=>'sending']),$config,$source,$global,$agent,$job),'A recovered in-flight send must not be sent again');
foreach([['mode'=>'bad'],['reply_limit'=>0],['min_interval'=>-1],['publication_ids'=>['bad-id']]] as $bad) {
    try{Policy::normalize($bad);throw new RuntimeException('Invalid configuration accepted');}catch(DomainException){++$checks;}
}
check(Policy::defaults()['mode']==='off' && !Policy::defaults()['mark_spam_enabled'] && !Policy::defaults()['public_reply_enabled'],'Defaults must be disabled');
echo "PASS $checks comment automation flow and queue safety checks, no database or network\n";
