<?php
require __DIR__.'/../../Application/Mobile/AudioStore.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\AudioStore;
$root=sys_get_temp_dir().'/mautic-audio-test-'.bin2hex(random_bytes(6));mkdir($root,0700);
$store=new AudioStore($root);
function box(string $type,string $body):string{return pack('N',8+strlen($body)).$type.$body;}
$header=str_repeat("\0",12).pack('NN',16000,16000);
$media=box('mdhd',$header).box('hdlr',str_repeat("\0",8).'soun'.str_repeat("\0",12)).box('minf',box('stbl',box('stsd',str_repeat("\0",4).pack('N',1).box('mp4a',str_repeat("\0",28)))));
$bytes=box('ftyp','M4A '.str_repeat("\0",4)).box('moov',box('trak',box('mdia',$media))).box('mdat',str_repeat('a',64));
$result=$store->store(31,9,19,'test-audio-request-123456',base64_encode($bytes));
assert($store->store(31,9,19,'test-audio-request-123456',base64_encode($bytes))===$result);
assert($store->forReply($result['audio_id'],31,9,'test-audio-request-123456')['asset']===19);
$cases=[fn()=>AudioStore::decode(base64_encode(str_replace('soun','vide',$bytes))),fn()=>AudioStore::decode(base64_encode(substr($bytes,0,20))),fn()=>AudioStore::decode(base64_encode('https://malicious.test/audio')),fn()=>AudioStore::decode(base64_encode(str_repeat('a',AudioStore::MAX_BYTES+1))),fn()=>$store->forReply($result['audio_id'],32,9,'test-audio-request-123456'),fn()=>$store->forReply($result['audio_id'],31,8,'test-audio-request-123456'),fn()=>$store->record('../../etc/passwd'),fn()=>$store->store(31,9,19,'test-audio-request-123456',base64_encode(str_replace(str_repeat('a',64),str_repeat('b',64),$bytes)))];
foreach($cases as $case){try{$case();throw new RuntimeException('Unsafe audio accepted');}catch(DomainException){}}
assert((fileperms($store->record($result['audio_id'])['file'])&0777)===0600);
echo "Private audio validation, scope and upload idempotency passed. No database accessed.\n";
// Only the disposable filesystem fixture created above is removed.
foreach(glob($root.'/var/inbox-mobile/audio/*') as $file)unlink($file);rmdir($root.'/var/inbox-mobile/audio');rmdir($root.'/var/inbox-mobile');rmdir($root.'/var');rmdir($root);
