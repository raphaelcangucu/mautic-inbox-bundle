<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Entity;

use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use PHPUnit\Framework\TestCase;

final class ConversationStateTest extends TestCase
{
    public function testNewInboundStyleTransitionDoesNotReleaseHumanTakeover(): void
    {
        $state = (new ConversationState())->setHumanTakeover(true)->setLifecycle('resolved')->setNeedsResponse(false)->setSnoozedUntil(new \DateTimeImmutable('+1 day'));
        $state->setLifecycle('open')->setNeedsResponse(true)->setSnoozedUntil(null);

        self::assertTrue($state->isHumanTakeover());
        self::assertTrue($state->needsResponse());
        self::assertSame('open', $state->getLifecycle());
        self::assertNull($state->getSnoozedUntil());
    }
}
