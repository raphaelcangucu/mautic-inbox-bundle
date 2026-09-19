<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MauticPlugin\MauticInboxBundle\Application\ReplyAvailability;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\UnresolvedRecipient;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReplyAvailabilityTest extends TestCase
{
    /**
     * A chave de `settings` onde o plugin por QR grava o estado do numero
     * (MauticWhatsQrBundle\Domain\SessionState::SETTING_STATUS). O teste escreve a string
     * porque e assim que ela chega do banco: se alguem renomear a chave la, e este teste
     * que precisa falhar.
     */
    private const QR_SESSION_STATUS = 'whatsqr_session_status';

    /**
     * A recusa que o canal homologado devolve quando a janela de sessao fechou.
     */
    private const WABA_WINDOW_CLOSED = 'mautic.inbox.ui.no_message_was_received_in_the_last_24_hours_use_an_approved_what_bcb7ba';

    public function testTheTwentyFourHourWindowDoesNotApplyToAQrAsset(): void
    {
        self::assertNull(
            $this->availability($this->inbound('-30 hours'))->reason($this->state(AssetType::WhatsAppQrSession)),
            'A janela de 24h e regra do WABA e so existe dentro dele: uma sessao por QR nao tem janela de sessao para expirar. Aplica-la aqui calaria o atendente num canal onde nada fechou.',
        );
    }

    public function testAReconnectingAssetStillAllowsReplies(): void
    {
        self::assertNull(
            $this->availability($this->inbound('-5 minutes'))->reason(
                $this->state(AssetType::WhatsAppQrSession, [self::QR_SESSION_STATUS => 'reconnecting']),
            ),
            'Um numero reconectando volta sozinho. O desenho quer o compositor aberto e a resposta indo para a fila, para sair quando ele voltar -- fechar aqui e o oposto disso.',
        );
    }

    /**
     * Os estados em que ninguem chega sozinho: o pareamento foi desfeito e so um scan novo
     * o refaz, ou a sessao morreu de vez. A fila do conector descarta a resposta em duas
     * horas, entao deixar escrever aqui e deixar escrever para o vazio.
     */
    #[DataProvider('statesThatNeedSomebody')]
    public function testAnAssetThatOnlyComesBackWithHelpOffersNoReply(string $status): void
    {
        self::assertSame(
            'mautic.inbox.ui.reply_qr_session_disconnected',
            $this->availability($this->inbound('-5 minutes'))->reason(
                $this->state(AssetType::WhatsAppQrSession, [self::QR_SESSION_STATUS => $status]),
            ),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function statesThatNeedSomebody(): iterable
    {
        yield 'logged out' => ['logged_out'];
        yield 'failed'     => ['failed'];
    }

    public function testTheOfficialWhatsAppWindowIsUnchanged(): void
    {
        self::assertSame(
            self::WABA_WINDOW_CLOSED,
            $this->availability($this->inbound('-30 hours'))->reason($this->state(AssetType::WhatsAppPhoneNumber)),
            'A janela de 24h e regra do WABA e continua valendo para o numero homologado. Afrouxa-la quebra o canal homologado em silencio: o envio sai, a Meta recusa, e quem descobre e o cliente que nunca recebeu.',
        );

        self::assertNull(
            $this->availability($this->inbound('-1 hour'))->reason($this->state(AssetType::WhatsAppPhoneNumber)),
            'E dentro da janela o numero homologado continua respondivel: a guarda nao pode virar um "nunca".',
        );
    }

    public function testAConversationWithAnUnresolvedRecipientOffersNoReplyAndSaysWhy(): void
    {
        $reason = $this->availability($this->inbound('-5 minutes'))->reason($this->state(
            AssetType::WhatsAppQrSession,
            [self::QR_SESSION_STATUS => 'connected'],
            UnresolvedRecipient::PREFIX.'220518514233310@lid',
        ));

        self::assertSame(
            'mautic.inbox.ui.reply_recipient_unresolved',
            $reason,
            'O destinatario guarda um identificador opaco, e nao um telefone. O envio ja nasce recusado em definitivo, entao o compositor precisa fechar antes -- e dizer por que, ou o atendente vai reclamar do canal.',
        );
    }

    /**
     * Uma chave sem texto nos dois catalogos chega ao atendente como
     * "mautic.inbox.ui.alguma_coisa" no lugar do motivo. O canal fica fechado e ninguem
     * entende por que.
     */
    #[DataProvider('newReasons')]
    public function testEveryReasonIsWrittenInBothCatalogs(string $key): void
    {
        foreach (['en_US', 'pt_BR'] as $locale) {
            $catalog = (string) file_get_contents(__DIR__.'/../../../Translations/'.$locale.'/messages.ini');
            self::assertMatchesRegularExpression('/^'.preg_quote($key, '/').'="[^"]+"$/m', $catalog, $locale.' nao define '.$key);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function newReasons(): iterable
    {
        yield 'unresolved recipient' => ['mautic.inbox.ui.reply_recipient_unresolved'];
        yield 'qr session down'      => ['mautic.inbox.ui.reply_qr_session_disconnected'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function state(AssetType $type, array $settings = [], string $recipient = '5511999999999'): ConversationState
    {
        $asset = (new MetaAsset())->setType($type)->setStatus('active');
        $asset->setSettings($settings);
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('whatsapp')->setRecipient($recipient);

        return (new ConversationState())->setConversation($conversation);
    }

    /**
     * A caixa com uma unica mensagem recebida no historico. Sem contato ligado de
     * proposito: e o que mantem a leitura na consulta simples, em vez da que so existe
     * para reencontrar um numero brasileiro renormalizado.
     */
    private function availability(MetaMessage $lastInbound): ReplyAvailability
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($lastInbound);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ReplyAvailability($entityManager, $translator);
    }

    private function inbound(string $when): MetaMessage
    {
        return (new MetaMessage())->setDateAdded(new \DateTimeImmutable($when));
    }
}
