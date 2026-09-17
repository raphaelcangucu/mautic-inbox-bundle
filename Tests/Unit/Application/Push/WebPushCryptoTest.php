<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use MauticPlugin\MauticInboxBundle\Application\Push\WebPushCrypto;
use PHPUnit\Framework\TestCase;

final class WebPushCryptoTest extends TestCase
{
    public function testEachDerivedValueMatchesTheRfc(): void
    {
        $crypto = new WebPushCrypto();

        $derived = $crypto->derive(
            RfcVectors::decode(RfcVectors::UA_PUBLIC),
            RfcVectors::decode(RfcVectors::AUTH_SECRET),
            RfcVectors::privatePem(
                RfcVectors::decode(RfcVectors::AS_PRIVATE),
                RfcVectors::decode(RfcVectors::AS_PUBLIC),
            ),
            RfcVectors::decode(RfcVectors::SALT),
        );

        self::assertSame(RfcVectors::ECDH_SECRET, RfcVectors::encode($derived['sharedSecret']), 'segredo ECDH');
        self::assertSame(RfcVectors::CEK, RfcVectors::encode($derived['contentEncryptionKey']), 'CEK');
        self::assertSame(RfcVectors::NONCE, RfcVectors::encode($derived['nonce']), 'NONCE');
    }
}
