<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use MauticPlugin\MauticInboxBundle\Application\ChannelTransportRegistry;
use MauticPlugin\MauticInboxBundle\Application\ReplyAvailability;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class QrReplyAvailabilityTest extends TestCase
{
    public function testPairedQrCanReplyWithoutACloudApiServiceWindow(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::never())->method('findOneBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->willReturn($repository);

        self::assertNull($this->availability($em)->reason($this->state('connected')));
    }

    public function testDisconnectedQrIsBlockedBeforeLookingForMessageHistory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        self::assertSame('mautic.inbox.ui.reply_qr_session_disconnected', $this->availability($em)->reason($this->state('logged_out')));
    }

    public function testOpaquePrivacyIdentifierIsNotTreatedAsAPhone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $state = $this->state('connected');
        $state->getConversation()->setRecipient('jid:123456@lid');
        self::assertSame('mautic.inbox.ui.reply_recipient_unresolved', $this->availability($em)->reason($state));
    }

    public function testResolvedQrStillRequiresReopening(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        self::assertSame('mautic.inbox.ui.this_conversation_is_resolved_reopen_it_to_reply_fd61b2', $this->availability($em)->reason($this->state('connected')->setLifecycle('resolved')));
    }

    private function availability(EntityManagerInterface $em): ReplyAvailability
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return new ReplyAvailability($em, $translator, new ChannelTransportRegistry(new \ArrayIterator([])));
    }

    private function state(string $status): ConversationState
    {
        $asset = (new MetaAsset())->setType(AssetType::WhatsAppQrSession)->setStatus('active')->setIsPublished(true)->setSettings(['whatsqr_session_status' => $status]);
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('whatsapp')->setRecipient('5511999990000');

        return (new ConversationState())->setConversation($conversation);
    }
}
