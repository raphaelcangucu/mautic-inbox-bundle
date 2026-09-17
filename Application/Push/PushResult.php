<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * O que aconteceu com uma tentativa de entrega, ja classificado.
 *
 * Existe para que quem chama o PushSender nunca precise interpretar codigo HTTP: a decisao
 * entre apagar o aparelho, reenfileirar e descartar e tomada uma vez, aqui do lado.
 */
final class PushResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly bool $retryable,
        public readonly bool $retireDevice,
        public readonly ?int $retryAfter,
        public readonly int $statusCode,
        public readonly string $message,
    ) {
    }

    public static function delivered(int $statusCode): self
    {
        return new self(true, false, false, null, $statusCode, 'entregue');
    }

    /** A inscricao morreu com a desinstalacao do app: 404 ou 410. */
    public static function retire(int $statusCode, string $message): self
    {
        return new self(false, false, true, null, $statusCode, $message);
    }

    /** Defeito nosso, nao do aparelho. Repetir so repete a falha. */
    public static function discard(int $statusCode, string $message): self
    {
        return new self(false, false, false, null, $statusCode, $message);
    }

    public static function retry(int $statusCode, ?int $retryAfter, string $message): self
    {
        return new self(false, true, false, $retryAfter, $statusCode, $message);
    }
}
