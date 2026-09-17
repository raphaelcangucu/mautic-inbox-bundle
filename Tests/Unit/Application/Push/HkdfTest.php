<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use MauticPlugin\MauticInboxBundle\Application\Push\Hkdf;
use PHPUnit\Framework\TestCase;

final class HkdfTest extends TestCase
{
    public function testExtractProducesThirtyTwoOctets(): void
    {
        self::assertSame(32, strlen(Hkdf::extract('sal', 'material')));
    }

    public function testExpandHonoursTheRequestedLength(): void
    {
        $prk = Hkdf::extract('sal', 'material');

        self::assertSame(16, strlen(Hkdf::expand($prk, 'info', 16)));
        self::assertSame(12, strlen(Hkdf::expand($prk, 'info', 12)));
    }

    public function testDifferentInfoProducesDifferentKeys(): void
    {
        $prk = Hkdf::extract('sal', 'material');

        self::assertNotSame(Hkdf::expand($prk, 'a', 16), Hkdf::expand($prk, 'b', 16));
    }
}
