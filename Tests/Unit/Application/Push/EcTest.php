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

    public function testThePublicPointMatchesTheValueTheRfcPublishes(): void
    {
        $pem = RfcVectors::privatePem(
            RfcVectors::decode(RfcVectors::AS_PRIVATE),
            RfcVectors::decode(RfcVectors::AS_PUBLIC),
        );

        $point = Ec::pointFromKey(openssl_pkey_get_private($pem));

        self::assertSame(RfcVectors::AS_PUBLIC, RfcVectors::encode($point));
    }

    public function testCoordinatesShorterThanThirtyTwoOctetsArePaddedOnTheLeft(): void
    {
        // Uma coordenada com zero a esquerda volta do OpenSSL com menos de 32 octetos.
        // Sem preenchimento, o ponto sai deslocado e a cifra fica errada em silencio.
        $point = Ec::assemblePoint(str_repeat("\x11", 31), str_repeat("\x22", 32));

        self::assertSame(65, strlen($point));
        self::assertSame("\x04\x00\x11", substr($point, 0, 3));
    }

    public function testTheSpkiWrappingRoundTripsBackToTheSamePoint(): void
    {
        // Fecha o outro lado da tarefa 2: carregar sem erro nao prova que o prefixo DER esta certo,
        // porque um prefixo errado que ainda assim parseia passaria naquele teste. A ida e volta e
        // o que fecha, e so da para escrever aqui, onde pointFromKey existe.
        $point = RfcVectors::decode(RfcVectors::UA_PUBLIC);

        $restored = Ec::pointFromKey(openssl_pkey_get_public(Ec::publicPemFromPoint($point)));

        self::assertSame($point, $restored);
    }
}
