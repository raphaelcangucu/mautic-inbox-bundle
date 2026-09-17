<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Cifra de conteudo aes128gcm da RFC 8291 e cabecalho de autorizacao VAPID da RFC 8292.
 */
final class WebPushCrypto
{
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
}
