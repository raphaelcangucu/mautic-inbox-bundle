<?php
declare(strict_types=1);
require __DIR__.'/../../Application/OutboundFailure.php';
use MauticPlugin\MauticInboxBundle\Application\OutboundFailure;
function check(bool $value): void { if (!$value) throw new RuntimeException('Failure diagnostic regression'); }
check(OutboundFailure::describe('{"message":"Local anti-spam cooldown active for this recipient (60 seconds)."}') === ['code'=>'local_cooldown','seconds'=>60]);
check(OutboundFailure::describe('token=SECRET') === ['code'=>'delivery_failed','seconds'=>null]);
check(OutboundFailure::describe(null) === ['code'=>null,'seconds'=>null]);
echo "3 failure diagnostic cases passed; no database or kernel.\n";
