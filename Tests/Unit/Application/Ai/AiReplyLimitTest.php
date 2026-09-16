<?php

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Ai;

use MauticPlugin\MauticInboxBundle\Application\Ai\AiStore;
use PHPUnit\Framework\TestCase;

final class AiReplyLimitTest extends TestCase
{
    public function testZeroMeansUnlimitedAndPositiveValuesAreClamped(): void
    {
        self::assertSame(0, AiStore::normalizeLimit(null));
        self::assertSame(0, AiStore::normalizeLimit(-3));
        self::assertSame(15, AiStore::normalizeLimit('15'));
        self::assertSame(10000, AiStore::normalizeLimit(50000));
    }

    public function testLegacyInformationalLimitRemainsUnlimitedUntilSaved(): void
    {
        self::assertSame(0, AiStore::globalLimit(['limit' => 8]));
        self::assertSame(8, AiStore::globalLimit(['limit' => 8, 'limit_configured' => true]));
        self::assertSame(0, AiStore::agentLimit(['limit' => 8]));
        self::assertSame(8, AiStore::agentLimit(['limit' => 8, 'limit_configured' => true]));
    }

    public function testSmallestPositiveGlobalOrAgentLimitWins(): void
    {
        self::assertSame(0, AiStore::effectiveLimit(['limit' => 0], ['limit' => 0, 'limit_configured' => true]));
        self::assertSame(7, AiStore::effectiveLimit(['limit' => 7, 'limit_configured' => true], ['limit' => 0, 'limit_configured' => true]));
        self::assertSame(4, AiStore::effectiveLimit(['limit' => 0], ['limit' => 4, 'limit_configured' => true]));
        self::assertSame(4, AiStore::effectiveLimit(['limit' => 9, 'limit_configured' => true], ['limit' => 4, 'limit_configured' => true]));
    }
}
