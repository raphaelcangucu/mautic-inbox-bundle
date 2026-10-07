<?php
// Pure policy and local file tests only. No kernel, database or network.
require __DIR__.'/../../Application/InboxException.php';
require __DIR__.'/../../Application/Mobile/AssistantActionPolicy.php';
require __DIR__.'/../../Application/Mobile/AssistantProposalStore.php';
require __DIR__.'/../../Application/Mobile/AssistantQueryHints.php';
require __DIR__.'/../../Application/Mobile/AssistantCampaignReports.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\{AssistantActionPolicy as Policy,AssistantProposalStore as Store,AssistantCampaignReports as Reports};
use MauticPlugin\MauticInboxBundle\Application\InboxException;
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function denied(callable $f,int $status):void{try{$f();}catch(InboxException $e){check($e->httpStatus===$status,'wrong denial');return;}throw new RuntimeException('expected denial');}
check(Policy::normalize(['tool'=>'mautic_reply_inbox','id'=>9,'body'=>' oi ','reply_mode'=>'public','confirm'=>true,'token'=>'x'])===['tool'=>'mautic_reply_inbox','id'=>9,'body'=>'oi','reply_mode'=>'public'],'model fields stripped');
foreach([['tool'=>'delete','id'=>1],['tool'=>'mautic_reply_inbox','arguments'=>['id'=>1,'body'=>'hi']],['tool'=>'campaign_update','id'=>1,'data'=>['isPublished'=>true]],['tool'=>'campaign_update','id'=>1,'data'=>['name'=>'']],['tool'=>'campaign_update','id'=>1,'data'=>['allowRestart'=>'true']],['tool'=>'mautic_reply_inbox','id'=>'1','body'=>'hi'],['tool'=>'mautic_reply_inbox','id'=>1,'body'=>'hi','reply_mode'=>'evil'],['tool'=>'campaign_add_contacts','id'=>1,'contact_ids'=>['2']]]as$input)denied(fn()=>Policy::normalize($input),($input['tool']==='delete'?403:422));
check(Reports::channels([['type'=>'email.send'],['type'=>'meta.instagram.comment'],['type'=>'meta.instagram.comment.public_reply'],['type'=>'meta.whatsapp.send']])===['instagram_comments','whatsapp'],'channels from events, deduped');
check(Reports::day('2026-10-07')['from']==='2026-10-07T03:00:00+00:00','São Paulo boundary in UTC');
try{Reports::day('2026-02-30');throw new RuntimeException('accepted bad date');}catch(InvalidArgumentException){}
$hints=\MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantQueryHints::calls('Associe somente o contato de teste ID 3673 a campanha de teste QA Assistente ID 55.');
check(count($hints)===2&&$hints[0]['id']===55&&$hints[1]['id']===3673,'typed explicit IDs');
$hints=\MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantQueryHints::calls('Responda publicamente ao meu comentario de teste no atendimento Instagram ID 5.');
check(count($hints)===2&&$hints[0]['id']===5&&$hints[0]['resource']==='conversation'&&$hints[1]['resource']==='timeline','comment hydration');
check(\MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantQueryHints::calls('Campanha XYZ e qual o ID?')===[],'no invented example target');
check(\MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantQueryHints::calls('campaign ID -1')===[],'no negative ID');
check(\MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantQueryHints::calls('Quantos comentarios da campanha Instagram ID 32?')===[['tool'=>'mautic_fetch_campaign','id'=>32]],'campaign ID is not an inbox ID');
$root=sys_get_temp_dir().'/inbox-proposals-test-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/project',0700);
$store=new Store($root.'/project');$p=$store->create(1,'admin',['tool'=>'campaign_update','id'=>2],['target'=>['id'=>2]]);$calls=0;
$execute=function()use(&$calls){++$calls;return ['status'=>'completed'];};
denied(fn()=>$store->execute($p['id'],2,'admin',fn()=>null,$execute),404);
denied(fn()=>$store->execute($p['id'],1,'other',fn()=>null,$execute),404);
denied(fn()=>$store->execute('../x',1,'admin',fn()=>null,$execute),404);
denied(fn()=>$store->result($p['id'],2,'admin',fn()=>null),404);
denied(fn()=>$store->result($p['id'],1,'admin',fn()=>throw new InboxException('revoked',403)),403);
denied(fn()=>$store->execute($p['id'],1,'admin',fn()=>throw new InboxException('revoked',403),$execute),403);check($calls===0,'revocation before action');
denied(fn()=>$store->result($p['id'],1,'admin',fn()=>null),409);
$store->execute($p['id'],1,'admin',fn()=>null,$execute);$store->execute($p['id'],1,'admin',fn()=>null,$execute);check($calls===1,'replay does not execute twice');
check($store->result($p['id'],1,'admin',fn()=>null)['status']==='completed','read completed result');
denied(fn()=>$store->result($p['id'],2,'admin',fn()=>null),404);
denied(fn()=>$store->result($p['id'],1,'admin',fn()=>throw new InboxException('revoked',403)),403);
denied(fn()=>$store->execute($p['id'],1,'admin',fn()=>throw new InboxException('revoked',403),$execute),403);
$f=$store->create(1,'admin',['tool'=>'campaign_update','id'=>3],[]);try{$store->execute($f['id'],1,'admin',fn()=>null,fn()=>throw new RuntimeException('uncertain'));}catch(RuntimeException){}
denied(fn()=>$store->execute($f['id'],1,'admin',fn()=>null,$execute),409);check($calls===1,'failed actions not blindly repeated');
$path=$root.'/inbox-mobile-private/assistant-actions/'.$f['id'].'.json';check((fileperms($path)&0777)===0600,'private file');
$e=$store->create(1,'admin',['tool'=>'x'],[]);$path=$root.'/inbox-mobile-private/assistant-actions/'.$e['id'].'.json';$record=json_decode(file_get_contents($path),true);$record['expires']=time()-1;file_put_contents($path,json_encode($record));denied(fn()=>$store->execute($e['id'],1,'admin',fn()=>null,$execute),409);
foreach(glob($root.'/inbox-mobile-private/assistant-actions/*.json')as$file)unlink($file);rmdir($root.'/inbox-mobile-private/assistant-actions');rmdir($root.'/inbox-mobile-private');rmdir($root.'/project');rmdir($root);
echo "Assistant actions: all checks passed\n";
