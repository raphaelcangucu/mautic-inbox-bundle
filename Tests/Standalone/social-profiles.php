<?php
// Pure URL/CRM fixtures. No kernel, database connection, schema or remote service.
require dirname(__DIR__,2).'/Application/Mobile/SocialProfileLinks.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\SocialProfileLinks as Profiles;
function same($actual,$expected):void {if($actual!==$expected)throw new RuntimeException('Unexpected profile URL');}
same(Profiles::url('instagram','@real.person',true),'https://www.instagram.com/real.person/');
same(Profiles::url('facebook','real.person',true),'https://www.facebook.com/real.person/');
same(Profiles::url('facebook','https://m.facebook.com/profile.php?id=123456789&tracking=unused'),'https://www.facebook.com/profile.php?id=123456789');
same(Profiles::url('facebook','https://www.facebook.com/people/Real-Person/123456789/'),'https://www.facebook.com/people/Real-Person/123456789/');
foreach(['123456789','Jane Doe','https://facebook.com/123456789','https://facebook.com/l.php?u=x','https://facebook.com/groups/123','https://facebook.com/reel/123','https://facebook.com/profile.php?id[]=123456789','https://facebook.com.evil.test/person','https://user:password@facebook.com/person','https://facebook.com:444/person'] as $unsafe)same(Profiles::url('facebook',$unsafe,true),null);
foreach(['https://instagram.com/p/123','https://instagram.com/reels/','https://instagram.com/123456','https://instagram.com/direct/','https://instagram.com/person%2Fother','https://instagram.com:444/person','http://instagram.com/person'] as $unsafe)same(Profiles::url('instagram',$unsafe,true),null);
$contact=new class {public function getFieldValue(string $field):?string{return ['instagram'=>'@real.person','facebook'=>'https://www.facebook.com/profile.php?id=123456789'][$field]??null;}};
same(Profiles::contact($contact),['instagram'=>'https://www.instagram.com/real.person/','facebook'=>'https://www.facebook.com/profile.php?id=123456789']);
echo "PASS participant public profiles, CRM fields, unsafe URLs and scoped-ID rejection (no database)\n";
