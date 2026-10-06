<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/Application/AssignmentPolicy.php';
use MauticPlugin\MauticInboxBundle\Application\AssignmentPolicy;
foreach ([
    [null, 8, false, true], [8, 8, false, true], [1, 8, false, false],
    [1, 8, true, true], [null, 8, true, true], [8, 8, true, true],
] as [$owner, $actor, $administrator, $expected]) {
    if (AssignmentPolicy::canTake($owner, $actor, $administrator) !== $expected) {
        throw new RuntimeException('Assignment permission regression');
    }
}
echo "6 assignment permission cases passed; no database or kernel used.\n";
