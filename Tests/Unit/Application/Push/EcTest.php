<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use MauticPlugin\MauticInboxBundle\Application\Push\Ec;
use PHPUnit\Framework\TestCase;

final class EcTest extends TestCase
{
    public function testARawPointBecomesAKeyOpenSslAccepts(): void
    {
        $point = RfcVectors::decode(RfcVectors::UA_PUBLIC);
        self::assertSame(65, strlen($point));

        $pem = Ec::publicPemFromPoint($point);
        $key = openssl_pkey_get_public($pem);

        self::assertNotFalse($key, 'o ponto cru precisa virar chave publica carregavel');
        self::assertSame('prime256v1', openssl_pkey_get_details($key)['ec']['curve_name']);
    }

    public function testAPointThatIsNotUncompressedIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ec::publicPemFromPoint(str_repeat("\x00", 65));
    }
}
