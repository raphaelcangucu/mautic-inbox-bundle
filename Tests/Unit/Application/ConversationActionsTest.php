<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use PHPUnit\Framework\TestCase;

final class ConversationActionsTest extends TestCase
{
    /**
     * Teto que a fila do conector usa quando o canal caiu e volta sozinho
     * (OutboundQueue::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS).
     */
    private const TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS = 7200;

    public function testAQrSessionSendStillHasATryLeftAfterTheChannelHasBeenDownForTwoHours(): void
    {
        $attempts = ConversationActions::sendAttempts($this->asset(AssetType::WhatsAppQrSession));

        self::assertGreaterThanOrEqual(
            self::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS,
            self::lastTryAt($attempts),
            'A sessao por QR cai e volta sozinha. Se a ultima tentativa acontece antes do teto de duas horas, o backoff do conector nunca chega a ser usado e a resposta e recusada com o numero ainda fora do ar.',
        );
    }

    public function testAQrSessionSendDoesNotAskForMoreTriesThanTwoHoursNeed(): void
    {
        $attempts = ConversationActions::sendAttempts($this->asset(AssetType::WhatsAppQrSession));

        self::assertLessThan(
            self::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS,
            self::lastTryAt($attempts - 1),
            'Uma resposta parada expira em duas horas. Tentativa que acontece depois disso sai para um cliente que ja desistiu.',
        );
    }

    /**
     * @dataProvider officialAssets
     */
    public function testAnOfficialChannelSendIsTriedOnce(AssetType $type): void
    {
        self::assertSame(
            1,
            ConversationActions::sendAttempts($this->asset($type)),
            'O canal homologado que recusa recusou por um motivo que repetir nao conserta; repetir seria mensagem duplicada no cliente.',
        );
    }

    /** @return iterable<string, array{AssetType}> */
    public static function officialAssets(): iterable
    {
        yield 'WhatsApp phone number' => [AssetType::WhatsAppPhoneNumber];
        yield 'Instagram account'     => [AssetType::InstagramAccount];
        yield 'Facebook page'         => [AssetType::FacebookPage];
    }

    /**
     * Replica da curva da fila: min(teto, 2 ** (tentativa - 1) * 30) segundos de espera
     * depois de cada fracasso, e a ultima tentativa nao reagenda nada. Esta copia existe
     * para o teste falar em segundos, e nao num numero de tentativas que so quem tem a
     * formula na cabeca sabe conferir.
     */
    private static function lastTryAt(int $maxAttempts): int
    {
        $elapsed = 0;
        for ($try = 1; $try < $maxAttempts; ++$try) {
            $elapsed += min(self::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS, 2 ** ($try - 1) * 30);
        }

        return $elapsed;
    }

    private function asset(AssetType $type): MetaAsset
    {
        return (new MetaAsset())->setType($type);
    }
}
