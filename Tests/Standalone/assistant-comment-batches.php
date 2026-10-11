<?php
// Pure policy checks. No kernel, database or network.
require __DIR__.'/../../Application/Mobile/AssistantCommentBatchPolicy.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantCommentBatchPolicy as Policy;
function check($v,$m){if(!$v)throw new RuntimeException($m);}
check(Policy::explicitScan('Scan all available comments',['supported_actions'=>['comment_batches']]),'full scan bypasses unnecessary model planning');
check(!Policy::explicitScan('All campaigns',['supported_actions'=>['comment_batches']]),'campaigns do not become comment scans');
check(!Policy::explicitScan('Scan all comments',[]),'old client keeps its existing workflow');
$s=['channel'=>'instagram','asset_active'=>true,'body'=>'Hello','publication_id'=>'post','lifecycle'=>'open','needs_response'=>true];
check(Policy::replyEligible($s),'unanswered public comment eligible');
foreach(['already_replied','deleted','own_comment','body_truncated','spam','blocked','ai_assigned'] as $flag)check(!Policy::replyEligible($s+[$flag=>true]),'preserve '.$flag);
foreach(['channel'=>'whatsapp','asset_active'=>false,'lifecycle'=>'resolved','needs_response'=>false,'body'=>'','publication_id'=>''] as $k=>$v)check(!Policy::replyEligible(array_replace($s,[$k=>$v])),'invalid '.$k);
$items=[['id'=>1,'name'=>'Author','body'=>'Question','context_complete'=>true,'reply_available'=>true],['id'=>2,'name'=>'Other','body'=>'Truncated','context_complete'=>false]];
$result=Policy::classifications([['id'=>1,'classification'=>'legitimate','reason'=>'Question'],['id'=>2,'classification'=>'spam','reason'=>'bad'],['id'=>999,'classification'=>'spam','reason'=>'invented']],$items);
check(count($result)===2&&$result[0]['classification']==='legitimate'&&$result[1]['classification']==='uncertain','exact known IDs and unavailable preserve');
$a=['tool'=>'mautic_reply_inbox','id'=>1,'reply_mode'=>'public'];
check(Policy::batchReply($a,$items,true),'full authorized public reply supports batch');
check(!Policy::batchReply($a,$items,false),'old client retains cap');
check(!Policy::batchReply(array_replace($a,['reply_mode'=>'private']),$items,true),'private replies retain cap');
check(!Policy::batchReply(array_replace($a,['id'=>2]),$items,true),'unavailable comment cannot reply');
check(!Policy::batchReply(array_replace($a,['id'=>999]),$items,true),'hallucinated ID cannot reply');
echo "Assistant comment batches: all checks passed\n";
