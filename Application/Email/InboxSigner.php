<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Email;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticInboxBundle\Application\Push\Hkdf;

/**
 * Os links publicos que o Inbox poe no mundo: formulario, descadastro e o original do app.
 *
 * Cada um e um conjunto de inteiros empacotados e um HMAC-SHA256 truncado em 16 bytes, com uma
 * chave por finalidade derivada do secret_key do Mautic por HKDF. Um token de uma finalidade
 * nao vale em outra, e trocar o secret_key invalida todos -- aceitavel para links que expiram
 * ou que so desligam envio.
 */
final class InboxSigner
{
    public const FORM = 'mautic-inbox-form-v1';
    public const UNSUBSCRIBE = 'mautic-inbox-unsubscribe-v1';
    public const ORIGINAL = 'mautic-inbox-original-v1';

    private const MAC_BYTES = 16;

    public function __construct(private CoreParametersHelper $parameters, private ?\Symfony\Component\Routing\RequestContextAwareInterface $router = null)
    {
    }

    /** @param list<int> $values inteiros sem sinal de 32 bits */
    public function sign(string $purpose, array $values): string
    {
        foreach ($values as $value) {
            if ($value < 0 || $value > 0xFFFFFFFF) { throw new \InvalidArgumentException('Valor fora da faixa do token.'); }
        }
        $data = pack('N*', ...$values);

        return self::base64url($data.substr(hash_hmac('sha256', $data, $this->key($purpose), true), 0, self::MAC_BYTES));
    }

    /**
     * @return list<int>|null os mesmos inteiros, ou null se o token foi adulterado, e de outra
     *                        finalidade ou nao tem o tamanho esperado
     */
    public function verify(string $purpose, string $token, int $count): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $token)) { return null; }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if (false === $raw || strlen($raw) !== 4 * $count + self::MAC_BYTES) { return null; }
        $data = substr($raw, 0, 4 * $count);
        $mac = substr($raw, 4 * $count);
        if (!hash_equals(substr(hash_hmac('sha256', $data, $this->key($purpose), true), 0, self::MAC_BYTES), $mac)) { return null; }

        return array_values(unpack('N'.$count, $data) ?: []);
    }

    /** A raiz publica do Mautic, sem barra no fim. Vale tambem no cron, onde nao ha requisicao. */
    public function siteUrl(): string
    {
        $site = rtrim((string) $this->parameters->get('site_url'), '/');
        if ('' !== $site || null === $this->router) { return $site; }
        // Sem site_url configurado, o melhor que existe e a requisicao corrente.
        $context = $this->router->getContext();
        $port = 'https' === $context->getScheme() ? $context->getHttpsPort() : $context->getHttpPort();
        $default = 'https' === $context->getScheme() ? 443 : 80;

        return $context->getScheme().'://'.$context->getHost().($port !== $default ? ':'.$port : '').rtrim($context->getBaseUrl(), '/');
    }

    private function key(string $purpose): string
    {
        $secret = (string) $this->parameters->get('secret_key');
        if ('' === $secret) { throw new \RuntimeException('O Mautic nao tem secret_key configurado.'); }

        return Hkdf::expand(Hkdf::extract('mautic-inbox', $secret), $purpose, 32);
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
