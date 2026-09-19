<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Application\WhatsAppTemplates;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class WhatsAppTemplatesTest extends TestCase
{
    private const QR_REFUSAL = 'mautic.inbox.template.qr_session';

    public function testTheTemplatePathRefusesAQrAssetWithItsOwnReason(): void
    {
        $templates = $this->templates();
        $state = $this->qrConversation();

        try {
            $templates->catalog($state);
            self::fail('O catalogo precisa recusar uma sessao por QR: modelo e produto do WABA e nao existe deste lado.');
        } catch (InboxException $refusal) {
            self::assertSame(
                self::QR_REFUSAL,
                $refusal->getMessage(),
                'A recusa generica de "so WhatsApp" manda o atendente conferir o canal errado: a conversa E WhatsApp, o que nao existe e o modelo.',
            );
        }

        self::assertSame(
            self::QR_REFUSAL,
            $templates->blockedReason($state),
            'Quem so pergunta o motivo -- a tela de modelos e o retry de um envio que falhou -- precisa receber o mesmo motivo, e nao "este destinatario nao e um numero valido".',
        );
    }

    /**
     * O motivo precisa existir nos dois catalogos: a tela mostra o que o tradutor devolver,
     * e uma chave sem texto aparece crua para o atendente.
     */
    public function testTheRefusalIsWrittenInBothCatalogs(): void
    {
        foreach (['en_US', 'pt_BR'] as $locale) {
            $catalog = (string) file_get_contents(__DIR__.'/../../../Translations/'.$locale.'/messages.ini');
            self::assertMatchesRegularExpression('/^'.preg_quote(self::QR_REFUSAL, '/').'="[^"]+"$/m', $catalog, $locale.' nao define '.self::QR_REFUSAL);
        }
    }

    private function templates(): WhatsAppTemplates
    {
        // O Graph nunca deveria ser procurado por uma sessao por QR: ela vive fora dele, e
        // um mock estrito e o que denuncia a recusa chegando tarde demais.
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method(self::anything());

        return new WhatsAppTemplates(
            $this->createMock(EntityManagerInterface::class),
            $graph,
            $this->createMock(IdentityManager::class),
            new PhoneNormalizer(),
        );
    }

    private function qrConversation(): ConversationState
    {
        $asset = (new MetaAsset())->setType(AssetType::WhatsAppQrSession)->setStatus('active');
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('whatsapp')->setRecipient('5511999999999');

        return (new ConversationState())->setConversation($conversation);
    }
}
