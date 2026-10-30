<?php
require __DIR__.'/../../Application/Mobile/ModerationStore.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\ModerationStore;
function check(bool $value):void{if(!$value)throw new RuntimeException('Moderation isolation failed');}
$dir=sys_get_temp_dir().'/mautic_mobile_unit_'.bin2hex(random_bytes(8));$store=new ModerationStore($dir);
$c=['id'=>1,'channel'=>'instagram','asset'=>['id'=>2],'recipient'=>'real-author'];$same=$c;$same['id']=2;$other=$same;$other['asset']['id']=3;
check(!$store->flags($c)['spam']);$store->apply($c,'spam',7);check($store->flags($c)['spam']);check(!$store->flags($same)['spam']);$store->apply($c,'block',7);check($store->flags($same)['blockedAuthor']);check(!$store->flags($other)['blockedAuthor']);$store->apply($same,'unblock',7);check(!$store->flags($c)['blockedAuthor']);$store->apply($c,'restore',7);check(!$store->flags($c)['spam']);
// Hiding stopped being refused in 46b18cf, where it became a confirmed Instagram call;
// it is scoped like the others, and only an action the store does not know is refused.
$store->apply($c,'hide',7);check($store->flags($c)['hidden']);check(!$store->flags($same)['hidden']);$store->apply($c,'show',7);check(!$store->flags($c)['hidden']);
try{$store->apply($c,'burn',7);throw new RuntimeException('Unsupported moderation accepted');}catch(DomainException){}
unlink($dir.'/var/inbox-mobile/moderation.json');rmdir($dir.'/var/inbox-mobile');rmdir($dir.'/var');rmdir($dir);echo "PASS: spam restoration, author/channel/asset scope, reversible hiding, unknown action refused\n";
