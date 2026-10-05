<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/Application/Mobile/PublicationContext.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\PublicationContext;
foreach([null,'','http://www.instagram.com/p/x/','https://www.instagram.com.evil.test/p/x/','https://user@www.instagram.com/p/x/','javascript:bad','https://www.facebook.com/post'] as $url){if(PublicationContext::link($url,'instagram')!==null)throw new RuntimeException('Unsafe social URL accepted');}
if(PublicationContext::link('https://www.instagram.com/reel/valid/','instagram')===null)throw new RuntimeException('Valid permalink rejected');
if(PublicationContext::image('https://scontent.example.fbcdn.net/picture.jpg')===null)throw new RuntimeException('Valid Meta image rejected');
if(PublicationContext::image('https://scontent.example.fbcdn.net.evil.test/picture.jpg')!==null)throw new RuntimeException('Unsafe image accepted');
echo "PASS publication links, platform scope and image allowlist (no database)\n";
