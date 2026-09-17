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

    public static function pointFromKey(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('A chave nao expoe coordenadas de curva eliptica.');
        }

        return self::assemblePoint($details['ec']['x'], $details['ec']['y']);
    }

    public static function assemblePoint(string $x, string $y): string
    {
        return "\x04".self::pad32($x).self::pad32($y);
    }

    private static function pad32(string $coordinate): string
    {
        if (strlen($coordinate) > 32) {
            throw new \RuntimeException('Coordenada P-256 maior que 32 octetos.');
        }

        return str_pad($coordinate, 32, "\x00", STR_PAD_LEFT);
    }

    public static function signatureToRaw(string $der): string
    {
        $length = strlen($der);
        $offset = 0;

        $take = static function (int $count) use ($der, $length, &$offset): string {
            if ($offset + $count > $length) {
                throw new \RuntimeException('Assinatura DER truncada.');
            }
            $slice = substr($der, $offset, $count);
            $offset += $count;

            return $slice;
        };

        if ("\x30" !== $take(1)) {
            throw new \RuntimeException('Assinatura DER precisa comecar com SEQUENCE.');
        }
        $take(1); // comprimento da sequencia, sempre curto para P-256

        $readInteger = static function () use ($take): string {
            if ("\x02" !== $take(1)) {
                throw new \RuntimeException('Esperado INTEGER na assinatura DER.');
            }
            $value = ltrim($take(ord($take(1))), "\x00");
            if (strlen($value) > 32) {
                throw new \RuntimeException('Inteiro maior que 32 octetos numa assinatura P-256.');
            }

            return str_pad($value, 32, "\x00", STR_PAD_LEFT);
        };

        return $readInteger().$readInteger();
    }
}
