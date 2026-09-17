<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

/**
 * Vetores da RFC 8291, secao 5 e apendice A. Copiados literalmente da especificacao.
 */
final class RfcVectors
{
    public const PLAINTEXT      = 'V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24';
    public const UA_PUBLIC      = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    public const AUTH_SECRET    = 'BTBZMqHH6r4Tts7J_aSIgg';
    public const AS_PRIVATE     = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    public const AS_PUBLIC      = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    public const SALT           = 'DGv6ra1nlYgDCS1FRnbzlw';
    public const ECDH_SECRET    = 'kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs';
    public const CEK            = 'oIhVW04MRdy2XN9CiKLxTg';
    public const NONCE          = '4h_95klXJ5E_qnoN';
    public const BODY           = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public static function decode(string $base64Url): string
    {
        return base64_decode(strtr($base64Url, '-_', '+/').str_repeat('=', (4 - strlen($base64Url) % 4) % 4), true);
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Monta um PEM de chave privada a partir do escalar cru de 32 octetos e do ponto publico.
     *
     * Existe somente aqui, no teste: a RFC publica a chave do servidor como escalar cru, e o
     * OpenSSL nao aceita essa forma. O codigo de producao guarda PEM justamente para nao
     * precisar disto.
     */
    public static function privatePem(string $scalar32, string $point65): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".$scalar32
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$point65;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";
    }
}
