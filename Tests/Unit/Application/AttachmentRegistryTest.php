<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Application\AttachmentRegistry;
use MauticPlugin\MauticInboxBundle\Application\MessagePresentation;
use MauticPlugin\MauticInboxBundle\Contract\AttachmentProviderInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AttachmentRegistryTest extends TestCase
{
    public function testUnsafeUrlsAreRejectedAndMissingProvidersLeaveExistingPathAvailable(): void
    {
        $unsafe = $this->createMock(AttachmentProviderInterface::class); $unsafe->method('attachmentUrl')->willReturn('javascript:alert(1)');
        $safe = $this->createMock(AttachmentProviderInterface::class); $safe->method('attachmentUrl')->willReturn('https://mautic.test/s/whatsqr/media/15');
        $message = new MetaMessage();
        self::assertSame('https://mautic.test/s/whatsqr/media/15', (new AttachmentRegistry(new \ArrayIterator([$unsafe, $safe])))->url($message, 'image'));
        self::assertNull((new AttachmentRegistry(new \ArrayIterator([])))->url($message, 'image'));
    }

    public function testQrAttachmentHasAPreviewUrlCaptionAndDefaultLabelWithoutQueries(): void
    {
        $em = $this->createMock(EntityManagerInterface::class); $em->expects(self::never())->method('createQueryBuilder');
        $translator = $this->createMock(TranslatorInterface::class); $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        $router = $this->createMock(UrlGeneratorInterface::class); $router->expects(self::never())->method('generate');
        $provider = $this->createMock(AttachmentProviderInterface::class); $provider->method('attachmentUrl')->willReturn('https://mautic.test/s/whatsqr/media/15');
        $presentation = new MessagePresentation($em, $translator, $router, new AttachmentRegistry(new \ArrayIterator([$provider])));
        $message = (new MetaMessage())->setAsset(new MetaAsset())->setChannel('whatsapp')->setDirection('inbound')->setMessageType('image')->setPayload(['message' => ['text' => 'Veja esta imagem', 'image' => ['id' => str_repeat('a', 64), 'filename' => '', 'caption' => 'Veja esta imagem']]]);
        (new \ReflectionProperty(MetaMessage::class, 'id'))->setValue($message, 15);
        $result = $presentation->present($message);
        self::assertSame('Veja esta imagem', $result['body']); self::assertTrue($result['attachments'][0]['available']);
        self::assertSame('image', $result['attachments'][0]['type']); self::assertSame('https://mautic.test/s/whatsqr/media/15', $result['attachments'][0]['url']);
        self::assertNotSame('', $result['attachments'][0]['label']);
    }

    public function testOfficialWhatsAppMediaKeepsItsGraphProxyWhenNoQrProviderMatches(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class); $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        $router = $this->createMock(UrlGeneratorInterface::class); $router->expects(self::once())->method('generate')->with('mautic_inbox_media', ['messageId' => 15], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://mautic.test/s/inbox/media/15');
        $message = (new MetaMessage())->setChannel('whatsapp')->setDirection('inbound')->setMessageType('image')->setPayload(['message' => ['image' => ['id' => '123456789']]]);
        (new \ReflectionProperty(MetaMessage::class, 'id'))->setValue($message, 15);
        $presentation = new MessagePresentation($em, $translator, $router, new AttachmentRegistry(new \ArrayIterator([])));
        self::assertSame('https://mautic.test/s/inbox/media/15', $presentation->present($message)['attachments'][0]['url']);
    }
}
