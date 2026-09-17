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

    public function testADerSignatureBecomesSixtyFourRawOctets(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_sign('mensagem', $der, $key, OPENSSL_ALGO_SHA256);

        self::assertSame(64, strlen(Ec::signatureToRaw($der)));
    }

    public function testAnIntegerShorterThanThirtyTwoOctetsIsPaddedOnTheLeft(): void
    {
        // O caso espelhado do teste abaixo: um INTEGER que e genuinamente curto porque o
        // proprio valor comeca com zero. Sem preenchimento, R sai com 31 octetos e o FCM
        // recusa — mas so em cerca de uma assinatura em 128, que e o pior jeito de falhar.
        $r31 = str_repeat("\x11", 31);
        $s32 = str_repeat("\x22", 32);
        $der = "\x30".chr(4 + 31 + 32)."\x02".chr(31).$r31."\x02".chr(32).$s32;

        $raw = Ec::signatureToRaw($der);

        self::assertSame(64, strlen($raw));
        self::assertSame("\x00".$r31, substr($raw, 0, 32), 'R curto precisa ser preenchido a esquerda');
        self::assertSame($s32, substr($raw, 32, 32));
    }

    public function testATruncatedSignatureIsRefusedInsteadOfSilentlyWrong(): void
    {
        $this->expectException(\RuntimeException::class);
        Ec::signatureToRaw("\x30\x44\x02\x20".str_repeat("\x11", 8));
    }

    public function testIntegersWithALeadingZeroKeepTheirValue(): void
    {
        // DER assina inteiros: um valor cujo primeiro bit e 1 ganha um 0x00 na frente.
        // Removido sem cuidado, R ou S saem com 31 octetos e a assinatura e recusada.
        $r   = "\x00".str_repeat("\xff", 32);
        $s   = str_repeat("\x11", 32);
        $der = "\x30".chr(4 + strlen($r) + strlen($s))
            ."\x02".chr(strlen($r)).$r
            ."\x02".chr(strlen($s)).$s;

        $raw = Ec::signatureToRaw($der);

        self::assertSame(64, strlen($raw));
        self::assertSame(str_repeat("\xff", 32), substr($raw, 0, 32));
        self::assertSame($s, substr($raw, 32, 32));
    }
}
