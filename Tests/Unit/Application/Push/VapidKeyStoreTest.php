<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSettingStore;
use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeyStore;
use PHPUnit\Framework\TestCase;

/** O mesmo contrato estreito do PushSettingRepository, sem banco nenhum. */
final class InMemorySettings implements PushSettingStore
{
    /** @var array<string, string> */
    public array $rows = [];

    public function get(string $name): ?string
    {
        return $this->rows[$name] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->rows[$name] = $value;
    }
}

final class VapidKeyStoreTest extends TestCase
{
    public function testTheStoredPairComesBackIdentical(): void
    {
        $settings = new InMemorySettings();
        $store    = new VapidKeyStore($this->encryption(), $settings);

        $generated = $store->generate();

        self::assertArrayHasKey('vapid_private', $settings->rows);
        self::assertStringNotContainsString('BEGIN', $settings->rows['vapid_private'], 'a privada nao pode ficar em claro na tabela');

        $loaded = $store->load();
        self::assertSame($generated->privatePem(), $loaded->privatePem());
        self::assertSame($generated->publicKey(), $loaded->publicKey());
    }

    public function testTheStoredPublicKeyIsReadableWithoutDecrypting(): void
    {
        $settings = new InMemorySettings();
        $store    = new VapidKeyStore($this->encryption(), $settings);

        $generated = $store->generate();

        // O navegador recebe esta chave de qualquer jeito; decifrar na requisicao mais quente do
        // fluxo nao compraria nada.
        self::assertSame($generated->publicKey(), $settings->rows['vapid_public']);
    }

    public function testLoadingWithoutAPairReportsThatPushIsOff(): void
    {
        $store = new VapidKeyStore($this->encryption(), new InMemorySettings());

        self::assertFalse($store->isConfigured());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mautic:inbox:push:setup/');
        $store->load();
    }

    /**
     * Um selo de mentira, mas reversivel e reconhecivel: basta para provar que a linha
     * gravada nao e o PEM em claro.
     */
    private function encryption(): EncryptionHelper
    {
        $helper = $this->createMock(EncryptionHelper::class);
        $helper->method('encrypt')->willReturnCallback(static fn ($data): string => 'sealed:'.base64_encode((string) $data));
        $helper->method('decrypt')->willReturnCallback(static fn ($data) => str_starts_with((string) $data, 'sealed:') ? base64_decode(substr((string) $data, 7)) : false);

        return $helper;
    }
}
