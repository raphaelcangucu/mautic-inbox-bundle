<?php
/** Pure file/crypto checks in a disposable local directory; never boots Mautic or a DB. */
declare(strict_types=1);
use MauticPlugin\MauticInboxBundle\Application\Mobile\Push\{PrivateStorage,ApnsConfiguration,NativePushRegistry,ApnsSender,NativePushWorker};
use MauticPlugin\MauticInboxBundle\Application\Mobile\SessionStore;
spl_autoload_register(function(string $class): void { $prefix='MauticPlugin\\MauticInboxBundle\\'; if(str_starts_with($class,$prefix)) { $file=dirname(__DIR__,2).'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if(is_file($file)) require $file; } });
function verify(bool $result,string $name): void { if(!$result)throw new RuntimeException($name); echo 'PASS '.$name.PHP_EOL; }
$root=sys_get_temp_dir().'/mautic-native-push-test-'.bin2hex(random_bytes(6));mkdir($root.'/project',0700,true);
try {
    $storage=new PrivateStorage($root.'/project');$config=new ApnsConfiguration($storage);$registry=new NativePushRegistry($storage,$config);
    $sessions=new SessionStore($root.'/project');$verifier=str_repeat('a',43);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $code=$sessions->authorize(8,'fingerprint',$challenge,'test://callback');$tokens=$sessions->exchange($code,$verifier,'test://callback');$grant=$sessions->authenticate($tokens['access_token']);
    verify($sessions->activeSession($grant['session'])['user']===8,'active renewable grant');
    $installation='11111111-1111-1111-1111-111111111111';
    $device=['installation'=>$installation,'token'=>str_repeat('a',64),'environment'=>'development','accountId'=>str_repeat('b',64),'bundle'=>'com.distributionmachine.mauticinbox.demo','name'=>'Macro','preferences'=>['enabled'=>true,'preview'=>false]];
    $status=$registry->register($grant,$device);verify($status['registered']&&!$status['configured'],'registration without configured key is honest');
    verify(!array_key_exists('token',$status),'status never discloses device token');
    try{$registry->register($grant,array_replace($device,['token'=>'https://bad.example']));throw new RuntimeException('accepted invalid token');}catch(DomainException){echo "PASS invalid token rejected\n";}
    $items=[['stateId'=>232,'messageId'=>99]];$registry->enqueue($items);$registry->enqueue($items);
    $data=$storage->transaction(static fn(array &$d)=>$d);verify(count($data['jobs'])===1,'queue idempotency');verify((fileperms($storage->directory.'/native-push.json')&0077)===0,'private atomic queue');
    $d=array_values($data['devices'])[0];$payload=NativePushWorker::payload($d,['contact_name'=>'SECRET CONTACT','preview'=>'SECRET BODY'],232);
    $job=['state'=>232,'message'=>99];
    $conversation=['lifecycle'=>'open','unread'=>1,'assigneeId'=>null,'lastInboundId'=>99];
    $eligible=static fn(array $state):bool=>NativePushWorker::conversationEligible($d,$job,$state,8,time());
    verify($eligible($conversation),'unassigned unread conversation remains eligible');
    verify(!$eligible(array_replace($conversation,['lifecycle'=>'resolved'])),'resolved queued conversation is suppressed even when still unread');
    verify(!$eligible(array_replace($conversation,['lifecycle'=>'snoozed'])),'snoozed queued conversation is suppressed');
    verify(!$eligible(array_replace($conversation,['unread'=>0])),'reading before delivery suppresses queued alert');
    verify(!$eligible(array_replace($conversation,['assigneeId'=>9])),'transfer before delivery suppresses former operator alert');
    verify($eligible(array_replace($conversation,['assigneeId'=>8])),'current assignee can receive queued alert');
    verify(!$eligible(array_replace($conversation,['lastInboundId'=>100])),'newer inbound replaces grouped queued alert');
    verify(!$eligible(array_replace($conversation,['spam'=>true]))&&!$eligible(array_replace($conversation,['blockedAuthor'=>true])),'moderation before delivery suppresses queued alert');
    $present=array_replace($d,['foreground'=>true,'open'=>232,'seen'=>time()]);
    verify(!NativePushWorker::conversationEligible($present,$job,$conversation,8,time()),'open conversation with fresh presence suppresses alert');
    verify(NativePushWorker::conversationEligible(array_replace($present,['seen'=>time()-61]),$job,$conversation,8,time()),'expired foreground presence no longer suppresses alert');
    verify(!NativePushWorker::conversationEligible($d,$job,[],8,time()),'deleted conversation cannot receive alert');
    verify(!str_contains(json_encode($payload),'SECRET'),'default payload excludes customer details');verify($payload['accountId']===$device['accountId']&&$payload['conversationId']===232,'notification opens correct account and conversation');
    $d['preferences']['preview']=true;$d['preferences']['sound']=false;
    $payload=NativePushWorker::payload($d,['contact_name'=>'Contact','preview'=>'Message'],232);verify($payload['aps']['alert']['body']==='Message'&&!isset($payload['aps']['sound']),'preview opt-in and silent payload');
    verify(ApnsSender::retryable(429)&&ApnsSender::retryable(503)&&!ApnsSender::retryable(410)&&!ApnsSender::retryable(403),'bounded retries exclude invalid tokens and credentials');
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($key,$pem);
    $jwt=ApnsSender::sign(['team_id'=>'SB6QYUH97U','key_id'=>'ABCDEFGHIJ','private_key'=>$pem],1700000000);$parts=explode('.',$jwt);$raw=base64_decode(strtr($parts[2],'-_','+/'));verify(strlen($raw)===64,'ES256 signature uses JOSE representation');
    $integer=static function(string $v):string{$v=ltrim($v,"\x00");if($v==='')$v="\x00";if(ord($v[0])&128)$v="\x00".$v;return "\x02".chr(strlen($v)).$v;};$seq=$integer(substr($raw,0,32)).$integer(substr($raw,32));$der="\x30".chr(strlen($seq)).$seq;verify(openssl_verify($parts[0].'.'.$parts[1],$der,openssl_pkey_get_details($key)['key'],OPENSSL_ALGO_SHA256)===1,'provider JWT cryptographically verifies');
    $provider=['team_id'=>'SB6QYUH97U','key_id'=>'ABCDEFGHIJ','private_key'=>$pem];$providerToken=$config->providerToken('test-key',$provider);verify($providerToken===(new ApnsConfiguration($storage))->providerToken('test-key',$provider),'cron workers reuse provider JWT instead of rotating every minute');
    $next=$sessions->refresh($tokens['refresh_token']);verify($sessions->authenticate($next['access_token'])['session']===$grant['session'],'token rotation preserves push session');
    $sessions->revoke($next['access_token']);verify($sessions->activeSession($grant['session'])===null,'logout invalidates push grant');
    $registry->remove($grant,$installation);verify(!$registry->status($grant,$installation)['registered'],'unregister removes device');
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
}
