<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * O conteudo da notificacao, dentro do orcamento que a especificacao permite.
 *
 * O limite de 4096 octetos da RFC 8030 vale para o CORPO CIFRADO, nao para o texto claro.
 * Descontado o enquadramento — 86 de cabecalho, 1 de delimitador, 16 de etiqueta GCM — sobram
 * 3993 octetos. Truncar em 4096 gera corpos que o servico de push recusa com 413.
 */
final class PushPayload
{
    public const MAX_PLAINTEXT = WebPushCrypto::MAX_PLAINTEXT;

    public static function forInboundMessage(
        string $contactName,
        string $preview,
        int $conversationId,
        string $conversationUrl,
    ): string {
        $title = '' !== trim($contactName) ? trim($contactName) : 'Mensagem recebida';

        $encode = static fn (string $body): string => (string) json_encode([
            'title'          => $title,
            'body'           => $body,
            'conversationId' => $conversationId,
            'url'            => $conversationUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $payload = $encode(self::oneLine($preview));
        if (strlen($payload) <= self::MAX_PLAINTEXT) {
            return $payload;
        }

        // Encolhe somente a previa, nunca o titulo nem o destino: uma notificacao sem para
        // onde ir e pior que uma notificacao curta.
        $room = self::MAX_PLAINTEXT - strlen($encode(''));

        return $encode(self::cut(self::oneLine($preview), max(0, $room)));
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Corta em octetos sem partir caractere multibyte — emoji e acento inclusive.
     */
    private static function cut(string $text, int $octets): string
    {
        if (strlen($text) <= $octets) {
            return $text;
        }
        if ($octets <= 1) {
            return '';
        }

        // Um octeto reservado para o sinal de corte, que tambem e multibyte em UTF-8.
        $ellipsis = '…';
        $kept     = mb_strcut($text, 0, max(0, $octets - strlen($ellipsis)), 'UTF-8');

        return rtrim($kept).$ellipsis;
    }
}
