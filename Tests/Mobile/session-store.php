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
