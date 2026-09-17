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

    public function testTheEncryptedBodyReproducesTheRfcVector(): void
    {
        $body = (new WebPushCrypto())->encrypt(
            RfcVectors::decode(RfcVectors::PLAINTEXT),
            RfcVectors::decode(RfcVectors::UA_PUBLIC),
            RfcVectors::decode(RfcVectors::AUTH_SECRET),
            RfcVectors::decode(RfcVectors::SALT),
            RfcVectors::privatePem(
                RfcVectors::decode(RfcVectors::AS_PRIVATE),
                RfcVectors::decode(RfcVectors::AS_PUBLIC),
            ),
        );

        self::assertSame(RfcVectors::BODY, RfcVectors::encode($body));
    }

    public function testARandomlyKeyedBodyStaysWithinTheRecordSize(): void
    {
        $body = (new WebPushCrypto())->encrypt(
            str_repeat('a', 3993),
            RfcVectors::decode(RfcVectors::UA_PUBLIC),
            RfcVectors::decode(RfcVectors::AUTH_SECRET),
        );

        self::assertSame(4096, strlen($body), 'o teto de 3993 octetos existe para o corpo fechar em 4096');
    }

    public function testAPlaintextOverTheCeilingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebPushCrypto())->encrypt(
            str_repeat('a', 3994),
            RfcVectors::decode(RfcVectors::UA_PUBLIC),
            RfcVectors::decode(RfcVectors::AUTH_SECRET),
        );
    }

    public function testTheAuthorizationHeaderVerifiesAgainstItsOwnKey(): void
    {
        $keys   = \MauticPlugin\MauticInboxBundle\Application\Push\VapidKeys::generate();
        $header = (new WebPushCrypto())->authorizationHeader(
            'https://fcm.googleapis.com/fcm/send/abc123',
            'mailto:suporte@exemplo.com',
            $keys,
        );

        self::assertStringStartsWith('vapid t=', $header);
        self::assertStringContainsString(', k='.$keys->publicKey(), $header);

        [$header64, $claims64, $signature64] = explode('.', substr($header, 8, strpos($header, ', k=') - 8));
        $claims = json_decode(RfcVectors::decode($claims64), true);

        self::assertSame('https://fcm.googleapis.com', $claims['aud'], 'aud e a origem do endpoint, nao a URL inteira');
        self::assertSame('mailto:suporte@exemplo.com', $claims['sub']);
        self::assertGreaterThan(time(), $claims['exp']);
        self::assertLessThanOrEqual(time() + 86400, $claims['exp']);

        $der = self::rawSignatureToDer(RfcVectors::decode($signature64));
        self::assertSame(1, openssl_verify(
            $header64.'.'.$claims64,
            $der,
            openssl_pkey_get_public(\MauticPlugin\MauticInboxBundle\Application\Push\Ec::publicPemFromPoint(RfcVectors::decode($keys->publicKey()))),
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function testTwoEndpointsOnDifferentOriginsGetDifferentAudiences(): void
    {
        $keys   = \MauticPlugin\MauticInboxBundle\Application\Push\VapidKeys::generate();
        $crypto = new WebPushCrypto();

        $google  = $crypto->authorizationHeader('https://fcm.googleapis.com/fcm/send/a', 'mailto:a@b.c', $keys);
        $mozilla = $crypto->authorizationHeader('https://updates.push.services.mozilla.com/wpush/v2/a', 'mailto:a@b.c', $keys);

        self::assertNotSame($google, $mozilla, 'reaproveitar um token entre origens e o erro classico');
    }

    public function testASubjectThatIsNotMailtoOrHttpsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebPushCrypto())->authorizationHeader('https://fcm.googleapis.com/fcm/send/a', 'suporte@exemplo.com', \MauticPlugin\MauticInboxBundle\Application\Push\VapidKeys::generate());
    }

    private static function rawSignatureToDer(string $raw): string
    {
        $integer = static function (string $value): string {
            $value = ltrim($value, "\x00");
            if ('' === $value) {
                $value = "\x00";
            }
            if (ord($value[0]) >= 0x80) {
                $value = "\x00".$value; // DER assina inteiros: bit alto ligado exige o zero na frente
            }

            return "\x02".chr(strlen($value)).$value;
        };

        $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
