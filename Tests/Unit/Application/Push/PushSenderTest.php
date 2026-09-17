<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSender;
use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeyStore;
use MauticPlugin\MauticInboxBundle\Application\Push\WebPushCrypto;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PushSenderTest extends TestCase
{
    public function testATwoHundredOneCountsAsDelivered(): void
    {
        $result = $this->sendWith(new MockResponse('', ['http_code' => 201]));

        self::assertTrue($result->delivered);
        self::assertFalse($result->retryable);
        self::assertFalse($result->retireDevice);
    }

    public function testATwoHundredTwoAlsoCountsAsDelivered(): void
    {
        self::assertTrue($this->sendWith(new MockResponse('', ['http_code' => 202]))->delivered);
    }

    public function testAFourHundredFourRetiresTheSubscription(): void
    {
        $result = $this->sendWith(new MockResponse('', ['http_code' => 404]));

        self::assertTrue($result->retireDevice);
        self::assertFalse($result->retryable, 'aparelho desinstalado nao volta tentando de novo');
    }

    public function testAFourHundredTenRetiresTheSubscription(): void
    {
        self::assertTrue($this->sendWith(new MockResponse('', ['http_code' => 410]))->retireDevice);
    }

    public function testAFourHundredThirteenIsDiscardedNotRetried(): void
    {
        $result = $this->sendWith(new MockResponse('', ['http_code' => 413]));

        self::assertFalse($result->retryable, 'repetir um corpo grande demais so repete a falha');
        self::assertFalse($result->retireDevice, 'o defeito e nosso, nao do aparelho');
    }

    public function testAFourHundredTwentyNineIsRetryableAndCarriesRetryAfter(): void
    {
        $result = $this->sendWith(new MockResponse('', [
            'http_code'        => 429,
            'response_headers' => ['Retry-After' => '120'],
        ]));

        self::assertTrue($result->retryable);
        self::assertSame(120, $result->retryAfter);
    }

    public function testAFiveHundredIsRetryable(): void
    {
        self::assertTrue($this->sendWith(new MockResponse('', ['http_code' => 503]))->retryable);
    }

    public function testATransportErrorIsRetryable(): void
    {
        $result = $this->sendWith(new MockResponse('', ['error' => 'conexao recusada']));

        self::assertTrue($result->retryable, 'rede fora do ar volta depois');
        self::assertFalse($result->delivered);
    }

    public function testTheRequestCarriesTheEncodingTtlAndUrgencyHeaders(): void
    {
        $captured = null;
        $client   = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('', ['http_code' => 201]);
        });

        $this->senderWith($client)->send($this->device(), 'ola');

        $headers = array_map('strtolower', $captured['headers'] ?? []);
        self::assertContains('content-encoding: aes128gcm', $headers);
        self::assertContains('content-type: application/octet-stream', $headers);
        self::assertContains('ttl: 3600', $headers);
        self::assertContains('urgency: high', $headers);
        self::assertNotEmpty(array_filter($headers, static fn (string $h): bool => str_starts_with($h, 'authorization: vapid t=')));
    }

    public function testTheStoredKeysAreDecodedBeforeReachingTheCrypto(): void
    {
        // O aparelho guarda base64url; a criptografia da fase 1 so aceita octeto cru e recusa
        // qualquer outra coisa. Sem a conversao, encrypt lanca e o resultado vira descarte.
        // Este teste existe para que a conversao nunca suma numa refatoracao.
        $result = $this->sendWith(new MockResponse('', ['http_code' => 201]));

        self::assertTrue($result->delivered, 'chave em base64url precisa ser decodificada antes de cifrar');
    }

    public function testKeyMaterialThatIsNotValidBase64UrlIsDiscardedNotRetried(): void
    {
        $device = (new PushDevice())
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/abc')
            ->setKeys('nao-e-uma-chave', 'nem-isto');

        $result = $this->senderWith(new MockHttpClient(new MockResponse('', ['http_code' => 201])))->send($device, 'ola');

        self::assertFalse($result->delivered);
        self::assertFalse($result->retryable, 'chave corrompida nao melhora tentando de novo');
    }

    private function sendWith(MockResponse $response): \MauticPlugin\MauticInboxBundle\Application\Push\PushResult
    {
        return $this->senderWith(new MockHttpClient($response))->send($this->device(), 'ola');
    }

    private function senderWith(MockHttpClient $client): PushSender
    {
        $settings = new InMemorySettings();
        $store    = new VapidKeyStore($this->encryption(), $settings);
        $store->generate();
        $store->storeSubject('mailto:suporte@exemplo.com');

        return new PushSender($client, new WebPushCrypto(), $store);
    }

    private function device(): PushDevice
    {
        return (new PushDevice())
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/abc')
            ->setKeys(RfcVectors::UA_PUBLIC, RfcVectors::AUTH_SECRET);
    }

    private function encryption(): EncryptionHelper
    {
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('encrypt')->willReturnCallback(static fn (string $v): string => base64_encode($v));
        $encryption->method('decrypt')->willReturnCallback(static fn (string $v): string => (string) base64_decode($v, true));

        return $encryption;
    }
}
