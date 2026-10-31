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

$root=sys_get_temp_dir().'/mautic_password_unit_'.bin2hex(random_bytes(8));$store=new SessionStore($root);
// Ten attempts per identifier, counted before the password is checked.
for($i=0;$i<10;$i++)$store->throttlePassword('apple-review','10.0.0.1');
refused(fn()=>$store->throttlePassword('apple-review','10.0.0.1'));
refused(fn()=>$store->throttlePassword('APPLE-REVIEW','10.0.0.1'));
$store->throttlePassword('other-operator','10.0.0.1');
// Thirty per address, whichever identifier is tried.
for($i=0;$i<19;$i++)$store->throttlePassword('operator'.$i,'10.0.0.1');
refused(fn()=>$store->throttlePassword('operator-last','10.0.0.1'));
// A different address starts its own window; the identifier keeps its own count.
$store->throttlePassword('another-operator','10.0.0.2');
refused(fn()=>$store->throttlePassword('apple-review','10.0.0.2'));
$raw=file_get_contents($root.'/var/inbox-mobile/sessions.json');check(!str_contains($raw,'apple-review')&&!str_contains($raw,'10.0.0.1'),'no identifier or address persisted');
$session=$store->exchangePassword(9,'fingerprint');
check($store->authenticate($session['access_token'])['user']===9,'password user binding');
check($session['fingerprint']==='fingerprint'&&$session['user_id']===9,'caller can re-check the user');
check(!str_contains(file_get_contents($root.'/var/inbox-mobile/sessions.json'),$session['access_token']),'no bearer persisted');
$rotated=$store->refresh($session['refresh_token']);check($store->authenticate($rotated['access_token'])['user']===9,'password session refreshes');
$data=json_decode(file_get_contents($root.'/var/inbox-mobile/sessions.json'),true);$data['password_rates']['id:'.hash('sha256','apple-review')]['expires']=time()-1;file_put_contents($root.'/var/inbox-mobile/sessions.json',json_encode($data));
$store->throttlePassword('apple-review','10.0.0.3');
unlink($root.'/var/inbox-mobile/sessions.json');rmdir($root.'/var/inbox-mobile');rmdir($root.'/var');rmdir($root);echo "PASS: password throttle per identifier and address, hashed counters, window expiry, session binding and refresh\n";
