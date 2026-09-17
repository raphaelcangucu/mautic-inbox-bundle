<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

use MauticPlugin\MauticInboxBundle\Entity\PushDevice;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * O POST no servico de push e a classificacao da resposta.
 *
 * Nunca lanca por causa de uma resposta do servidor nem de uma falha de rede: todo desfecho
 * vira PushResult. Isso e deliberado, porque na fase 3 este caminho passa a ser acionado de
 * dentro do fluxo que grava a mensagem recebida do cliente, e uma excecao aqui nao pode
 * contaminar aquilo.
 */
final class PushSender
{
    private const TTL     = 3600;
    private const URGENCY = 'high';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly WebPushCrypto $crypto,
        private readonly VapidKeyStore $keys,
    ) {
    }

    public function send(PushDevice $device, string $payload): PushResult
    {
        try {
            // A fronteira de codificacao fica aqui, no ultimo momento possivel. O aparelho
            // guarda base64url porque foi assim que o navegador enviou; a criptografia so
            // aceita octeto cru e recusa qualquer outra coisa.
            $body = $this->crypto->encrypt(
                $payload,
                self::decode($device->getP256dh()),
                self::decode($device->getAuth()),
            );

            $authorization = $this->crypto->authorizationHeader(
                $device->getEndpoint(),
                (string) $this->keys->subject(),
                $this->keys->load(),
            );
        } catch (\Throwable $problem) {
            // Chave invalida, par VAPID ausente ou carga grande demais: nada disso melhora
            // tentando de novo.
            return PushResult::discard(0, $problem->getMessage());
        }

        try {
            $response = $this->http->request('POST', $device->getEndpoint(), [
                'headers' => [
                    'Authorization'    => $authorization,
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type'     => 'application/octet-stream',
                    'TTL'              => (string) self::TTL,
                    'Urgency'          => self::URGENCY,
                ],
                'body' => $body,
            ]);

            $status  = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (\Throwable $problem) {
            return PushResult::retry(0, null, $problem->getMessage());
        }

        return match (true) {
            201 === $status || 202 === $status || 200 === $status => PushResult::delivered($status),
            404 === $status || 410 === $status                    => PushResult::retire($status, 'inscricao inexistente no servico de push'),
            413 === $status                                       => PushResult::discard($status, 'corpo acima do limite do servico de push'),
            429 === $status                                       => PushResult::retry($status, self::retryAfter($headers), 'limite de taxa do servico de push'),
            $status >= 500                                        => PushResult::retry($status, self::retryAfter($headers), 'servico de push indisponivel'),
            default                                               => PushResult::discard($status, 'resposta inesperada do servico de push'),
        };
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;

        return null !== $value && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    private static function decode(string $base64Url): string
    {
        $decoded = base64_decode(strtr($base64Url, '-_', '+/').str_repeat('=', (4 - strlen($base64Url) % 4) % 4), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Material de chave do aparelho nao e base64url valido.');
        }

        return $decoded;
    }
}
