<?php
require __DIR__.'/../../Application/Mobile/SessionStore.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\SessionStore;
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);}
function refused(callable $f):void{try{$f();}catch(DomainException){return;}throw new RuntimeException('Expected refusal');}
$root=sys_get_temp_dir().'/mautic_mobile_unit_'.bin2hex(random_bytes(8));$store=new SessionStore($root);
$v=str_repeat('a',64);$challenge=rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=');$redirect='mautic-inbox-demo://oauth/callback';
$code=$store->authorize(7,'fingerprint',$challenge,$redirect);
refused(fn()=>$store->exchange($code,str_repeat('b',64),$redirect));refused(fn()=>$store->exchange($code,$v,'evil://callback'));
$session=$store->exchange($code,$v,$redirect);check($store->authenticate($session['access_token'])['user']===7,'user binding');refused(fn()=>$store->exchange($code,$v,$redirect));
$raw=file_get_contents($root.'/var/inbox-mobile/sessions.json');check(!str_contains($raw,$code)&&!str_contains($raw,$session['access_token'])&&!str_contains($raw,$session['refresh_token']),'no bearer persisted');
$rotated=$store->refresh($session['refresh_token']);refused(fn()=>$store->authenticate($session['access_token']));refused(fn()=>$store->refresh($session['refresh_token']));check($store->authenticate($rotated['access_token'])['user']===7,'rotation');
$store->revoke($rotated['access_token']);refused(fn()=>$store->authenticate($rotated['access_token']));refused(fn()=>$store->refresh($rotated['refresh_token']));refused(fn()=>$store->authenticate(str_repeat('f',64)));
$device=$store->startDevice($challenge,'unit-test');check($store->exchangeDevice($device['device_code'],$v)['error']==='authorization_pending','pending consent');refused(fn()=>$store->exchangeDevice($device['device_code'],str_repeat('b',64)));$store->approveDevice($device['user_code'],7,'fingerprint');refused(fn()=>$store->approveDevice($device['user_code'],8,'wrong-user'));$grant=$store->exchangeDevice($device['device_code'],$v);check($store->authenticate($grant['access_token'])['user']===7,'device bound user');refused(fn()=>$store->exchangeDevice($device['device_code'],$v));
unlink($root.'/var/inbox-mobile/sessions.json');rmdir($root.'/var/inbox-mobile');rmdir($root.'/var');rmdir($root);echo "PASS: PKCE, exact redirect, single-use code, hashed storage, refresh rotation, revoke, invalid bearer, device approval and PKCE\n";

$root=sys_get_temp_dir().'/mautic_magic_unit_'.bin2hex(random_bytes(8));$store=new SessionStore($root);
$magic=$store->startMagic('operator@example.com','test-address',$challenge,7,'fingerprint');
check(strlen($magic['code'])===6,'six-digit code');
$raw=file_get_contents($root.'/var/inbox-mobile/sessions.json');check(!str_contains($raw,$magic['request_id'])&&!str_contains($raw,$magic['code'])&&!str_contains($raw,'operator@example.com'),'no code, secret or email persisted');
refused(fn()=>$store->startMagic('operator@example.com','test-address',$challenge,7,'fingerprint'));
$wrong=$magic['code']==='000000'?'111111':'000000';
check($store->exchangeMagic($magic['request_id'],$wrong,$v)['error']==='invalid_grant','wrong code');
check($store->exchangeMagic($magic['request_id'],$magic['code'],str_repeat('b',64))['error']==='invalid_grant','PKCE binding');
$session=$store->exchangeMagic($magic['request_id'],$magic['code'],$v);check($store->authenticate($session['access_token'])['user']===7,'magic user binding');check($session['email_hash']===hash('sha256','operator@example.com'),'email change guard');check($store->exchangeMagic($magic['request_id'],$magic['code'],$v)['error']==='invalid_grant','magic single use');
$locked=$store->startMagic('locked@example.com','test-address',$challenge,7,'fingerprint');$wrong=$locked['code']==='000000'?'111111':'000000';for($i=0;$i<5;$i++)check($store->exchangeMagic($locked['request_id'],$wrong,$v)['error']==='invalid_grant','count failed attempt');check($store->exchangeMagic($locked['request_id'],$locked['code'],$v)['error']==='invalid_grant','five attempts locked');
$dummy=$store->startMagic('absent@example.com','test-address',$challenge,0,'');check($store->exchangeMagic($dummy['request_id'],$dummy['code'],$v)['error']==='invalid_grant','unknown operator never grants');
$expired=$store->startMagic('expired@example.com','test-address',$challenge,7,'fingerprint');$data=json_decode(file_get_contents($root.'/var/inbox-mobile/sessions.json'),true);$data['magic'][hash('sha256',$expired['request_id'])]['expires']=time()-1;file_put_contents($root.'/var/inbox-mobile/sessions.json',json_encode($data));check($store->exchangeMagic($expired['request_id'],$expired['code'],$v)['error']==='invalid_grant','expired code');
$resend=$store->startMagic('resend@example.com','test-address',$challenge,7,'fingerprint');$data=json_decode(file_get_contents($root.'/var/inbox-mobile/sessions.json'),true);$data['magic_rates']['email:'.hash('sha256','resend@example.com')]['last']=time()-61;file_put_contents($root.'/var/inbox-mobile/sessions.json',json_encode($data));$next=$store->startMagic('resend@example.com','test-address',$challenge,7,'fingerprint');check($store->exchangeMagic($resend['request_id'],$resend['code'],$v)['error']==='invalid_grant','resend invalidates old code');check(isset($store->exchangeMagic($next['request_id'],$next['code'],$v)['access_token']),'resend accepted');
unlink($root.'/var/inbox-mobile/sessions.json');rmdir($root.'/var/inbox-mobile');rmdir($root.'/var');rmdir($root);echo "PASS: magic expiry, PKCE, single-use, five-attempt lockout, resend invalidation, unknown user, hashed secrets and cooldown\n";
