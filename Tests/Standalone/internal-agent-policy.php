<?php
// Pure policy tests. No Composer/bootstrap/kernel, database or HTTP connection.
require __DIR__.'/../../Application/InboxException.php';
require __DIR__.'/../../Application/Ai/InternalAgentPolicy.php';
use MauticPlugin\MauticInboxBundle\Application\Ai\InternalAgentPolicy as Policy;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function denied(callable $call,int $status):void {try{$call();}catch(InboxException $e){check($e->httpStatus===$status,'wrong status');return;}throw new RuntimeException('expected denial');}
$agent=['key'=>'private','audience'=>'internal','enabled'=>true,'mcp_connection'=>'current_mautic','role_ids'=>[2],'mcp_tools'=>['mautic_search_contacts','mautic_search_campaigns','mautic_read_inbox']];
check(!Policy::internal(['profile'=>'macro-support']),'legacy must stay customer');
check(Policy::configured([['enabled'=>true],$agent])===[$agent],'internal discovery');
check(count(Policy::configured([array_replace($agent,['enabled'=>false])]))===1,'disabled internal cannot open legacy fallback');
check(Policy::visible($agent,42,2,true),'allowed role');
foreach([[0,2,true],[42,3,true],[42,null,true],[42,2,false]]as[$user,$role,$active])check(!Policy::visible($agent,$user,$role,$active),'user/role gating');
check(!Policy::visible(array_replace($agent,['enabled'=>false]),42,2,true),'disabled');
check(!Policy::visible(array_replace($agent,['mcp_connection'=>'remote_admin']),42,2,true),'foreign connection');
check(Policy::visible(array_replace($agent,['role_ids'=>[]]),42,3,true),'all eligible roles');
check(Policy::effectiveTools($agent,fn($p)=>$p==='lead:leads:viewown')===['mautic_search_contacts'],'tools never grant user permissions');
check(Policy::effectiveTools($agent,fn($p)=>false)===[],'revoked permissions');
check(Policy::effectiveTools(['mcp_tools'=>[]],fn($p)=>true)===[],'empty selection is not all tools');
foreach([['mcp_tools'=>['mautic_manage_contacts']],['mcp_connection'=>'remote_admin'],['role_ids'=>['2']],['audience'=>'admin']]as$override)denied(fn()=>Policy::normalize(array_replace($agent,$override)),422);
$normalized=Policy::normalize($agent);check($normalized['audience']==='internal','internal persist');
check(Policy::normalize([], $agent)===$normalized,'legacy admin saves preserve internal policy');
$customer=Policy::normalize(array_replace($agent,['audience'=>'customer']));check($customer['mcp_tools']===[]&&$customer['role_ids']===[]&&$customer['mcp_connection']===null,'customer has no internal connection');
check(Policy::select([$agent],[])===$agent,'single profile supports old clients');
check(Policy::select([$agent],['agent_key'=>'private'])===$agent,'explicit selection');
foreach([[[],[]],[[$agent,$agent],[]],[[$agent],['agent_key'=>'customer']],[[$agent],['agent_key'=>[]]]]as[$items,$request])denied(fn()=>Policy::select($items,$request),403);
echo "Internal agent policy: all checks passed\n";
