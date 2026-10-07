<?php
// Pure transformations only: no kernel, connection, fixtures or database.
require $argv[1] ?? dirname(__DIR__,2).'/Application/Mobile/MediaRoutes.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\MediaRoutes;
$cases=[
 ['/s/whatsqr/media/399','/s/inbox/api/media/399'],
 ['https://mautic.example/s/whatsqr/media/399','/s/inbox/api/media/399'],
 ['https://outside.example/s/whatsqr/media/399',null],
 ['http://mautic.example/s/whatsqr/media/399',null],
 ['//outside.example/s/whatsqr/media/399',null],
 ['https://mautic.example:8443/s/whatsqr/media/399',null],
 ['https://name@mautic.example/s/whatsqr/media/399',null],
 ['https://name:password@mautic.example/s/whatsqr/media/399',null],
 ['/s/whatsqr/media/399?redirect=external',null],
 ['/s/whatsqr/media/399#fragment',null],
 ['/s/whatsqr/media/0',null],
 ['/s/whatsqr/media/399/extra',null],
 ['/s/inbox/api/media/399',null],
 ['https://outside.example/image.png',null],
];
foreach($cases as [$url,$expected]) { $items=[['id'=>399,'attachments'=>[['url'=>$url,'type'=>'image','available'=>true]]]];$result=MediaRoutes::items($items,'https://mautic.example');if($result[0]['attachments'][0]['url']!==($expected??$url)||$result[0]['attachments'][0]['type']!=='image'||$result[0]['id']!==399)throw new RuntimeException('Invalid private media mapping'); }
if(MediaRoutes::items([['id'=>1]],'https://mautic.example')!==[['id'=>1]])throw new RuntimeException('Changed attachment-free message');
echo "15 pure private media checks passed; no database.\n";
