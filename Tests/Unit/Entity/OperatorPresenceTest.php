<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Entity;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\OperatorPresence;
use PHPUnit\Framework\TestCase;

final class OperatorPresenceTest extends TestCase
{
    public function testAnOperatorStartsAvailableSoInstallingThisChangesNothing(): void
    {
        self::assertTrue((new OperatorPresence())->isAvailable());
    }

    public function testTurningAvailabilityOffAndBackOnRecordsWhenItLastChanged(): void
    {
        $presence = (new OperatorPresence())->setUser(new User());
        $before   = $presence->getDateModified();

        $presence->setAvailable(false);
        self::assertFalse($presence->isAvailable());
        self::assertGreaterThanOrEqual($before, $presence->getDateModified());

        $presence->setAvailable(true);
        self::assertTrue($presence->isAvailable());
    }

    public function testThePresenceBelongsToTheUserItWasSetFor(): void
    {
        $user     = new User();
        $presence = (new OperatorPresence())->setUser($user);

        self::assertSame($user, $presence->getUser());
    }
}
