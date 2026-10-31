<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Email;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Application\Email\EmailContentRegistry;
use MauticPlugin\MauticInboxBundle\Application\Email\EmailHtmlSanitizer;
use MauticPlugin\MauticInboxBundle\Application\MessagePresentation;
use MauticPlugin\MauticInboxBundle\Contract\EmailContentProviderInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailPresentationTest extends TestCase
{
    public function testAnInboundEmailFollowsTheContractShape(): void
    {
        $item = $this->presentation(new StubEmailContent())->present($this->message());

        self::assertSame('Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**', $item['body'], 'body e o texto novo em markdown');
        self::assertSame('Re: Por que você ainda não depositou?', $item['subject']);
        self::assertSame(['name' => 'João Lead', 'address' => 'joao@example.com'], $item['email']['from']);
        self::assertSame([['name' => 'Macro Markets', 'address' => 'contato@macromarkets.com']], $item['email']['to'], 'O +tag do Reply-To carrega o token e nunca aparece na interface.');
        self::assertSame([['name' => '', 'address' => 'socio@example.com']], $item['email']['cc']);
        self::assertSame('> Em 23/10, Ana escreveu: ...', $item['email']['quoted']);
        self::assertTrue($item['email']['has_html']);
        self::assertTrue($item['email']['has_original']);
        self::assertFalse($item['email']['auto_reply']);
        self::assertFalse($item['email']['unverified_sender']);
        self::assertSame('message', $item['email']['kind']);
        self::assertStringNotContainsString('<script', $item['email']['html']);
        self::assertStringContainsString('src="https://mautic.test/s/inbox/api/email/7/attachments/1"', $item['email']['html'], 'A imagem cid: aponta para o proxy do anexo.');
    }

    public function testEveryAttachmentIsListedWithItsOwnProxyUrl(): void
    {
        $item = $this->presentation(new StubEmailContent())->present($this->message());

        self::assertSame([
            ['type' => 'document', 'label' => 'extrato.pdf', 'url' => 'https://mautic.test/s/inbox/api/email/7/attachments/0', 'available' => true, 'size' => 183422, 'mime' => 'application/pdf'],
            ['type' => 'image', 'label' => 'logo.png', 'url' => 'https://mautic.test/s/inbox/api/email/7/attachments/1', 'available' => true, 'size' => 2048, 'mime' => 'image/png'],
            ['type' => 'document', 'label' => 'virus.exe', 'url' => null, 'available' => false, 'size' => 10, 'mime' => 'application/octet-stream'],
        ], $item['attachments']);
    }

    public function testWithoutAnyProviderNothingIsAvailableAndNothingBreaks(): void
    {
        $item = $this->presentation(null)->present($this->message());

        self::assertFalse($item['email']['has_original']);
        foreach ($item['attachments'] as $attachment) {
            self::assertFalse($attachment['available']);
            self::assertNull($attachment['url']);
        }
        self::assertStringNotContainsString('cid:', $item['email']['html']);
    }

    public function testTheListPreviewSkipsTheHtml(): void
    {
        $item = $this->presentation(new StubEmailContent())->present($this->message(), false);

        self::assertArrayNotHasKey('html', $item['email']);
        self::assertTrue($item['email']['has_html']);
    }

    public function testAnAutoReplyIsLabelledAsOne(): void
    {
        $item = $this->presentation(null)->present($this->message(['kind' => 'auto_reply', 'auto_reply' => true, 'html' => '', 'attachments' => []]));

        self::assertTrue($item['email']['auto_reply']);
        self::assertSame('auto_reply', $item['email']['kind']);
        self::assertSame('mautic.inbox.ui.email_auto_reply', $item['content_label']);
        self::assertFalse($item['email']['has_html']);
        self::assertSame('', $item['email']['html']);
    }

    public function testAnUnreadableEmailSaysWhoSentIt(): void
    {
        $item = $this->presentation(null)->present($this->message(['kind' => 'unreadable', 'text' => '', 'html' => '', 'attachments' => []]));

        self::assertSame('mautic.inbox.ui.email_unreadable', $item['body']);
        self::assertSame('unreadable', $item['email']['kind']);
    }

    /** @param array<string,mixed> $overrides */
    private function message(array $overrides = []): MetaMessage
    {
        $asset = (new MetaAsset(31))->setType(AssetType::EmailAccount)->setName('Macro Markets')->setStatus('active');
        $asset->setSettings(['email_address' => 'contato@macromarkets.com']);

        return (new MetaMessage(7))->setAsset($asset)->setChannel('email')->setMessageType('email')->setDirection('inbound')->setRecipient('joao@example.com')->setPayload(['email' => $overrides + [
            'message_id' => '<CAF3x@mail.gmail.com>',
            'from' => ['name' => 'João Lead', 'address' => 'Joao@Example.com'],
            'to' => [['name' => 'Macro Markets', 'address' => 'contato+rk3xabcdefghijklmnopqrstu@macromarkets.com']],
            'cc' => ['socio@example.com'],
            'subject' => 'Re: Por que você ainda não depositou?',
            'text' => 'Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**',
            'quoted' => '> Em 23/10, Ana escreveu: ...',
            'html' => '<div dir="ltr">Oi Ana<script>alert(1)</script><img src="cid:logo@lead"></div>',
            'has_original' => true,
            'auto_reply' => false,
            'unverified_sender' => false,
            'kind' => 'message',
            'attachments' => [
                ['index' => 0, 'type' => 'document', 'name' => 'extrato.pdf', 'mime' => 'application/pdf', 'size' => 183422, 'cid' => null, 'available' => true],
                ['index' => 1, 'type' => 'image', 'name' => 'logo.png', 'mime' => 'image/png', 'size' => 2048, 'cid' => '<logo@lead>', 'available' => true],
                ['index' => 2, 'type' => 'executable', 'name' => '../../virus.exe', 'mime' => 'application/octet-stream', 'size' => 10, 'cid' => null, 'available' => false],
            ],
        ]]);
    }

    private function presentation(?EmailContentProviderInterface $provider): MessagePresentation
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => match ($route) {
            'mautic_inbox_email_attachment' => 'https://mautic.test/s/inbox/api/email/'.$parameters['messageId'].'/attachments/'.$parameters['index'],
            default => 'https://mautic.test/'.$route,
        });

        return new MessagePresentation(
            $this->createMock(EntityManagerInterface::class),
            $translator,
            $urls,
            null,
            new EmailContentRegistry(null === $provider ? [] : [$provider]),
            new EmailHtmlSanitizer(),
        );
    }
}

/** O provider do Multi Mail, reduzido ao que a apresentacao pergunta. */
final class StubEmailContent implements EmailContentProviderInterface
{
    public function supports(MetaMessage $message): bool { return 'email' === $message->getChannel(); }
    public function originalHtml(MetaMessage $message): ?string { return '<p>original</p>'; }
    public function attachment(MetaMessage $message, int $index): ?array { return null; }
}
