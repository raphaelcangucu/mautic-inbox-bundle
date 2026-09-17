<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Cifra de conteudo aes128gcm da RFC 8291 e cabecalho de autorizacao VAPID da RFC 8292.
 */
final class WebPushCrypto
{
    public const MAX_PLAINTEXT = 3993; // 4096 - 86 de cabecalho - 1 de delimitador - 16 de etiqueta
    private const RECORD_SIZE = 4096;

    /**
     * @return array{sharedSecret:string, contentEncryptionKey:string, nonce:string, serverPoint:string}
     */
    public function derive(string $userAgentPoint, string $authSecret, string $serverPrivatePem, string $salt): array
    {
        $serverKey = openssl_pkey_get_private($serverPrivatePem);
        if (false === $serverKey) {
            throw new \RuntimeException('Chave privada do servidor invalida.');
        }

        $serverPoint  = Ec::pointFromKey($serverKey);
        $sharedSecret = openssl_pkey_derive(Ec::publicPemFromPoint($userAgentPoint), $serverKey, 32);
        if (false === $sharedSecret) {
            throw new \RuntimeException('Falha ao derivar o segredo ECDH.');
        }

        // A ordem e obrigatoria: ponto do aparelho primeiro, ponto do servidor depois.
        $keyInfo = "WebPush: info\x00".$userAgentPoint.$serverPoint;
        $ikm     = Hkdf::expand(Hkdf::extract($authSecret, $sharedSecret), $keyInfo, 32);
        $prk     = Hkdf::extract($salt, $ikm);

        return [
            'sharedSecret'         => $sharedSecret,
            'contentEncryptionKey' => Hkdf::expand($prk, "Content-Encoding: aes128gcm\x00", 16),
            'nonce'                => Hkdf::expand($prk, "Content-Encoding: nonce\x00", 12),
            'serverPoint'          => $serverPoint,
        ];
    }

    public function encrypt(
        string $plaintext,
        string $userAgentPoint,
        string $authSecret,
        ?string $salt = null,
        ?string $serverPrivatePem = null,
    ): string {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('Texto claro acima de '.self::MAX_PLAINTEXT.' octetos nao cabe num registro.');
        }

        $salt ??= random_bytes(16);
        if (16 !== strlen($salt)) {
            throw new \InvalidArgumentException('O salt do aes128gcm tem exatamente 16 octetos.');
        }

        // Par efemero, gerado por mensagem. NAO e o par VAPID: aquele identifica o servidor e
        // vive para sempre; este existe para cifrar uma notificacao e e descartado. Sao a mesma
        // curva, o que torna a confusao facil e cara.
        $serverPrivatePem ??= self::ephemeralPem();

        $derived = $this->derive($userAgentPoint, $authSecret, $serverPrivatePem, $salt);

        $tag    = '';
        $cipher = openssl_encrypt(
            $plaintext."\x02", // 0x02 marca o ultimo registro
            'aes-128-gcm',
            $derived['contentEncryptionKey'],
            OPENSSL_RAW_DATA,
            $derived['nonce'],
            $tag,
        );
        if (false === $cipher) {
            throw new \RuntimeException('Falha na cifra AES-128-GCM.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$derived['serverPoint'].$cipher.$tag;
    }

    private static function ephemeralPem(): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (false === $key || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Nao foi possivel gerar o par efemero.');
        }

        return $pem;
    }
}
