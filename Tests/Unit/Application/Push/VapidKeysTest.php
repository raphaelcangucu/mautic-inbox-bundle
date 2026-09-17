<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeys;
use PHPUnit\Framework\TestCase;

final class VapidKeysTest extends TestCase
{
    public function testAGeneratedPairRoundTripsThroughStorage(): void
    {
        $generated = VapidKeys::generate();
        $restored  = VapidKeys::fromStorage($generated->privatePem(), $generated->publicKey());

        self::assertSame($generated->privatePem(), $restored->privatePem());
        self::assertSame($generated->publicKey(), $restored->publicKey());
    }

    public function testThePublicKeyIsTheRawPointInBase64Url(): void
    {
        $keys = VapidKeys::generate();

        self::assertSame(87, strlen($keys->publicKey()), '65 octetos em base64url dao 87 caracteres');
        self::assertStringStartsWith("\x04", RfcVectors::decode($keys->publicKey()));
    }

    public function testAPublicKeyThatDoesNotMatchThePrivateOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VapidKeys::fromStorage(VapidKeys::generate()->privatePem(), VapidKeys::generate()->publicKey());
    }
}
