<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use PHPUnit\Framework\TestCase;

final class RfcVectorsTest extends TestCase
{
    public function testThePrivateKeyFixtureLoadsInOpenSsl(): void
    {
        $pem = RfcVectors::privatePem(
            RfcVectors::decode(RfcVectors::AS_PRIVATE),
            RfcVectors::decode(RfcVectors::AS_PUBLIC),
        );

        $key = openssl_pkey_get_private($pem);
        self::assertNotFalse($key, 'o PEM montado a partir do escalar cru precisa carregar');

        $details = openssl_pkey_get_details($key);
        self::assertSame('prime256v1', $details['ec']['curve_name']);
    }
}
