<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * O par VAPID. A privada vive em PEM; a publica, no ponto cru que o navegador espera.
 */
final class VapidKeys
{
    private function __construct(
        private readonly string $privatePem,
        private readonly string $publicKey,
    ) {
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (false === $key) {
            throw new \RuntimeException('Nao foi possivel gerar o par VAPID.');
        }

        if (!openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Nao foi possivel exportar a chave privada VAPID.');
        }

        return new self($pem, self::encode(Ec::pointFromKey($key)));
    }

    public static function fromStorage(string $privatePem, string $publicKey): self
    {
        $key = openssl_pkey_get_private($privatePem);
        if (false === $key) {
            throw new \InvalidArgumentException('Chave privada VAPID invalida.');
        }

        if (self::encode(Ec::pointFromKey($key)) !== $publicKey) {
            throw new \InvalidArgumentException('A chave publica guardada nao corresponde a privada.');
        }

        return new self($privatePem, $publicKey);
    }

    public function privatePem(): string
    {
        return $this->privatePem;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
