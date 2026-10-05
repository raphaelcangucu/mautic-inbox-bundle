<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/Application/InboxException.php';
require dirname(__DIR__,2).'/Application/ReplyMode.php';
use MauticPlugin\MauticInboxBundle\Application\{ReplyMode,InboxException};
foreach ([['instagram','comment:123',null,'private'],['instagram','comment:123','public','public'],['instagram','person',null,'private'],['facebook','comment:123',null,'public'],['facebook','person',null,'private'],['webchat','visitor',null,'private']] as [$channel,$recipient,$mode,$expected]) {
    if (ReplyMode::resolve($channel,$recipient,$mode) !== $expected) { throw new RuntimeException('Legacy route changed'); }
}
foreach ([['instagram','person','public'],['facebook','comment:123','private'],['whatsapp','person','public'],['instagram','comment:123','invalid']] as [$channel,$recipient,$mode]) {
    try { ReplyMode::resolve($channel,$recipient,$mode);throw new RuntimeException('Unsupported route accepted'); }
    catch (InboxException) {}
}
echo "PASS explicit comment routing and legacy defaults, no database\n";
