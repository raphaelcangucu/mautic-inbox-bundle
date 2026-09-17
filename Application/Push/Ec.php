<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Conversoes entre a forma crua do Web Push e a forma DER que o OpenSSL exige.
 */
final class Ec
{
    /**
     * Prefixo DER de um SubjectPublicKeyInfo de P-256 com ponto nao comprimido.
     * SEQUENCE(89) { SEQUENCE(19) { OID ecPublicKey, OID prime256v1 }, BIT STRING(66) { 0x00 } }
     */
    private const SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    public static function publicPemFromPoint(string $point): string
    {
        if (65 !== strlen($point) || "\x04" !== $point[0]) {
            throw new \InvalidArgumentException('Ponto P-256 nao comprimido precisa de 65 octetos comecando em 0x04.');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(self::SPKI_PREFIX.$point), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}
