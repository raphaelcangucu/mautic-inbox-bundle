<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/**
 * Quantas tentativas cada caminho de envio pede a fila do conector.
 *
 * O envio nao chega a sair: sem credencial e sem transporte registrado o despacho
 * imediato falha dentro da fila, que engole a excecao. O que estes testes olham e o
 * numero gravado no job no momento em que ele entrou na fila.
 */
final class OutboundAttemptsTest extends MauticMysqlTestCase
{
    /** Teto da fila para canal temporariamente indisponivel (OutboundQueue). */
    private const TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS = 7200;

    public function testAQrSessionReplyIsQueuedToOutliveTwoHoursOfADroppedSession(): void
    {
        $request = $this->reply(AssetType::WhatsAppQrSession, 'qr-session-reply-00001');

        self::assertGreaterThanOrEqual(
            self::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS,
            self::lastTryAt($request->getJob()->getMaxAttempts()),
            'A sessao por QR cai e volta sozinha, e a fila ja sabe esperar duas horas por ela. Se a ultima tentativa acontece antes disso, a espera nunca e usada e a resposta do atendente e recusada com o numero ainda fora do ar.',
        );
    }

    public function testAnOfficialWhatsAppReplyIsStillQueuedForASingleTry(): void
    {
        $request = $this->reply(AssetType::WhatsAppPhoneNumber, 'official-reply-000001');

        self::assertSame(
            1,
            $request->getJob()->getMaxAttempts(),
            'O canal homologado que recusa recusou por um motivo que repetir nao conserta; insistir seria mensagem duplicada no cliente.',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatsAppAssets')]
    public function testAManualRetryIsASingleTryOnEitherChannel(AssetType $type): void
    {
        $failed = $this->reply($type, 'retry-source-00000001');
        $failed->getJob()->setStatus('failed');
        static::getContainer()->get(MetaInboxIntegration::class)->outboundJobChanged($failed->getJob());
        self::assertSame('failed', $failed->getStatus());

        $retried = static::getContainer()->get(ConversationActions::class)->retry($failed, $this->admin(), 'retry-request-00000001');

        self::assertSame(
            1,
            $retried->getJob()->getMaxAttempts(),
            'O atendente apertou "tentar de novo" e espera uma tentativa. Uma serie automatica que ele nao pediu manda a mensagem quando ele ja resolveu por outro caminho.',
        );
    }

    /** @return iterable<string, array{AssetType}> */
    public static function whatsAppAssets(): iterable
    {
        yield 'QR session'          => [AssetType::WhatsAppQrSession];
        yield 'official phone number' => [AssetType::WhatsAppPhoneNumber];
    }

    private function reply(AssetType $type, string $requestId): OutboundRequest
    {
        $connection = (new MetaConnection())->setName('Attempts test')->setAppId('attempts-inbox-app')->setStatus('active');
        $asset = (new MetaAsset())->setConnection($connection)->setName('Attempts WhatsApp')->setExternalId('attempts-'.$type->value)->setType($type)->setStatus('active');
        $asset->setIsPublished(true);
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('whatsapp')->setRecipient('553184326486');
        $inbound = (new MetaMessage())->setAsset($asset)->setConversation($conversation)->setChannel('whatsapp')->setDirection('inbound')
            ->setMessageType('text')->setRecipient('553184326486')->setExternalId('attempts-inbound-'.$type->value)
            ->setPayload(['message' => ['text' => ['body' => 'O boleto nao chegou']]])->setStatus('received');
        foreach ([$connection, $asset, $conversation, $inbound] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        static::getContainer()->get(MetaInboxIntegration::class)->messagePersisted($inbound);

        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $actions = static::getContainer()->get(ConversationActions::class);
        $state = $actions->take($state, $this->admin(), $state->getVersion());

        return $actions->reply($state, $this->admin(), 'Ja estou verificando por aqui', $requestId);
    }

    private function admin(): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
    }

    /**
     * Replica da curva da fila: min(teto, 2 ** (tentativa - 1) * 30) segundos depois de
     * cada fracasso, e a ultima tentativa nao reagenda nada. A copia esta aqui para o
     * teste falar em segundos, e nao num numero de tentativas que so quem tem a formula
     * na cabeca sabe conferir.
     */
    private static function lastTryAt(int $maxAttempts): int
    {
        $elapsed = 0;
        for ($try = 1; $try < $maxAttempts; ++$try) {
            $elapsed += min(self::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS, 2 ** ($try - 1) * 30);
        }

        return $elapsed;
    }
}
