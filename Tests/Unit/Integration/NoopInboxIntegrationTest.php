<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Integration;

use MauticPlugin\MauticMetaBundle\Application\Support\NoopInboxIntegration;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use PHPUnit\Framework\TestCase;

final class NoopInboxIntegrationTest extends TestCase
{
    public function testMetaRemainsStandaloneWhenInboxIsAbsent(): void
    {
        self::assertTrue((new NoopInboxIntegration())->automationAllowed(new MetaAsset(), 'recipient'));
    }
}
