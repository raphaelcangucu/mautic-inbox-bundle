<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/Application/Mobile/PublicationContext.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\PublicationContext;
foreach([null,'','http://www.instagram.com/p/x/','https://www.instagram.com.evil.test/p/x/','https://user@www.instagram.com/p/x/','javascript:bad','https://www.facebook.com/post'] as $url){if(PublicationContext::link($url,'instagram')!==null)throw new RuntimeException('Unsafe social URL accepted');}
if(PublicationContext::link('https://www.instagram.com/reel/valid/','instagram')===null)throw new RuntimeException('Valid permalink rejected');
if(PublicationContext::image('https://scontent.example.fbcdn.net/picture.jpg')===null)throw new RuntimeException('Valid Meta image rejected');
if(PublicationContext::image('https://scontent.example.fbcdn.net.evil.test/picture.jpg')!==null)throw new RuntimeException('Unsafe image accepted');
echo "PASS publication links, platform scope and image allowlist (no database)\n";
$comment=['id'=>'comment','media'=>['id'=>'media'],'from'=>['id'=>'visitor']];
$media=['id'=>'media','owner'=>['id'=>'owner']];
PublicationContext::assertInstagramOwner($comment,$media,'comment','media','owner');
foreach([
    [$comment,array_replace($media,['owner'=>['id'=>'another-owner']])],
    [array_replace($comment,['media'=>['id'=>'another-media']]),$media],
    [array_replace($comment,['from'=>['id'=>'owner']]),$media],
    [array_replace($comment,['id'=>'another-comment']),$media],
] as [$badComment,$badMedia]){
    try{PublicationContext::assertInstagramOwner($badComment,$badMedia,'comment','media','owner');throw new RuntimeException('Cross-account or own-author moderation accepted');}
    catch(DomainException){}
}
echo "PASS Instagram comment/media/owner scope and own-author protection (no database)\n";
