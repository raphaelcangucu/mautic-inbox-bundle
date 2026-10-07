<?php
/** Pure payload checks: no Mautic kernel, autoloader, network or database connection. */
declare(strict_types=1);
require __DIR__.'/../../Application/Mobile/Push/NativePushLocale.php';
require __DIR__.'/../../Application/Mobile/Push/NativePushWorker.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\Push\NativePushLocale;
use MauticPlugin\MauticInboxBundle\Application\Mobile\Push\NativePushWorker;
function check(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
$device=['name'=>'Mautic Inbox','accountId'=>str_repeat('a',64),'preferences'=>['preview'=>false,'sound'=>true,'grouped'=>true]];
$expected=['pt-BR'=>'Você recebeu uma nova mensagem.','en'=>'You received a new message.','es'=>'Has recibido un mensaje nuevo.'];
foreach ($expected as $locale=>$body) {
    $localized=$device+['locale'=>$locale];
    check(NativePushLocale::checked($locale)===$locale,'Accepted locale');
    $payload=NativePushWorker::payload($localized,['contact_name'=>'Pessoa privada','preview'=>'Texto privado'],276);
    check($payload['aps']['alert']['body']===$body,'Functional body language');
    check($payload['aps']['alert']['title']==='Mautic Inbox','No contact leaked without preview');
    check($payload['conversationId']===276 && $payload['accountId']===$device['accountId'],'Tap route preserved');
    check($payload['aps']['sound']==='default','Sound preserved');
    check($payload['aps']['thread-id']==='inbox-'.$device['accountId'].'-276','Grouping preserved');
    $localized['preferences']['preview']=true;
    $raw='Olá, <b>Raphael</b> 😊';
    $preview=NativePushWorker::payload($localized,['contact_name'=>'Raphael','preview'=>$raw],276);
    check($preview['aps']['alert']['body']===$raw,'Customer text remains unchanged');
    check($preview['aps']['alert']['title']==='Mautic Inbox · Raphael','Customer name remains unchanged');
    $test=NativePushWorker::payload($localized,null,0);
    check($test['type']==='push_test' && $test['conversationId']===0,'Test delivery type preserved');
    check($test['aps']['alert']['body']===NativePushLocale::text($locale,'test'),'Localized test body');
}
check(NativePushWorker::payload($device,null,276)['aps']['alert']['body']===$expected['pt-BR'],'Old devices keep Portuguese');
check(NativePushWorker::payload($device+['locale'=>'unknown'],null,276)['aps']['alert']['body']===$expected['pt-BR'],'Stored invalid locale has bounded fallback');
foreach (['de','en-US','',null,[],1] as $bad) {
    try { NativePushLocale::checked($bad); throw new RuntimeException('Invalid locale accepted'); }
    catch (DomainException $e) {check($e->getMessage()==='invalid_device','Stable error code');}
}
echo "Native push locale checks passed without a kernel or database.\n";
