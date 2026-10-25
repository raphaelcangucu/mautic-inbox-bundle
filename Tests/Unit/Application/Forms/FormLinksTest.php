<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Forms;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticInboxBundle\Application\Email\InboxSigner;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormLinks;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use PHPUnit\Framework\TestCase;

final class FormLinksTest extends TestCase
{
    private const NOW = 1_790_000_000;

    public function testAMarkerBecomesAMarkdownLinkInAnEmail(): void
    {
        $body = (new FormLinks($this->entities(), $this->signer()))->expand("Responda, por favor:\n[[form:12]]", $this->conversation(), true, self::NOW);

        self::assertMatchesRegularExpression('#^Responda, por favor:\n\[Pesquisa: por que nao depositou\]\(https://localhost:8443/inbox/f/[A-Za-z0-9_-]+\)$#', $body);
    }

    /** Achado no teste real: "DEP 04 - Feedback livre (o que faltou)" virava "Feedback livre  o que faltou". */
    public function testParenthesesInTheFormNameSurviveOnlyBracketsGo(): void
    {
        $body = (new FormLinks($this->entities(true, "DEP 04 - Feedback [livre]\n(o que faltou)"), $this->signer()))->expand('[[form:12]]', $this->conversation(), true, self::NOW);

        self::assertMatchesRegularExpression('#^\[DEP 04 - Feedback livre \(o que faltou\)\]\(https://localhost:8443/inbox/f/[A-Za-z0-9_-]+\)$#', $body);
    }

    public function testTheSameMarkerBecomesAPlainLinkOnWhatsApp(): void
    {
        $body = (new FormLinks($this->entities(), $this->signer()))->expand('[[form:12]]', $this->conversation(), false, self::NOW);

        self::assertMatchesRegularExpression('#^Pesquisa: por que nao depositou: https://localhost:8443/inbox/f/[A-Za-z0-9_-]+$#', $body);
    }

    public function testTheLinkIdentifiesFormContactAndConversationForThirtyDays(): void
    {
        $links = new FormLinks($this->entities(), $this->signer());
        $token = basename($links->url(12, 5, 812, self::NOW));

        self::assertSame(['form' => 12, 'contact' => 5, 'conversation' => 812], $links->resolve($token, self::NOW + 29 * 86400));
        self::assertNull($links->resolve($token, self::NOW + 31 * 86400), 'Vencido.');
    }

    public function testATamperedOrForeignTokenIsRejected(): void
    {
        $links = new FormLinks($this->entities(), $this->signer());
        $token = basename($links->url(12, 5, 812, self::NOW));
        $tampered = substr($token, 0, -2).('AA' === substr($token, -2) ? 'BB' : 'AA');

        self::assertNull($links->resolve($tampered, self::NOW));
        self::assertNull($links->resolve('nao-e-token', self::NOW));
        self::assertNull((new FormLinks($this->entities(), $this->signer('outro-segredo')))->resolve($token, self::NOW), 'Outro secret_key, outro link.');
        $unsubscribe = $this->signer()->sign(InboxSigner::UNSUBSCRIBE, [12, 5, 812, self::NOW + 999]);
        self::assertNull($links->resolve($unsubscribe, self::NOW), 'Um token de descadastro nao abre formulario.');
    }

    public function testAnUnpublishedFormIsRefusedBeforeQueueing(): void
    {
        $this->expectException(InboxException::class);
        $this->expectExceptionMessage('mautic.inbox.ui.form_unpublished');
        (new FormLinks($this->entities(false), $this->signer()))->expand('[[form:12]]', $this->conversation(), true, self::NOW);
    }

    public function testAFormNeedsAContactToIdentify(): void
    {
        $this->expectException(InboxException::class);
        $this->expectExceptionMessage('mautic.inbox.ui.form_needs_contact');
        (new FormLinks($this->entities(), $this->signer()))->expand('[[form:12]]', $this->conversation(false), true, self::NOW);
    }

    public function testATextWithoutMarkersIsUntouched(): void
    {
        $links = new FormLinks($this->createMock(EntityManagerInterface::class), $this->signer());

        self::assertSame('Sem formulario [[form:abc]]', $links->expand('Sem formulario [[form:abc]]', $this->conversation(false), true, self::NOW));
        self::assertSame([12, 7], FormLinks::markers('[[form:12]] e [[form:7]] e [[form:12]]'));
    }

    private function entities(bool $published = true, string $name = 'Pesquisa: por que nao depositou'): EntityManagerInterface
    {
        $form = (new Form())->setName($name);
        $form->setIsPublished($published);
        $entities = $this->createMock(EntityManagerInterface::class);
        $entities->method('find')->willReturnCallback(static fn (string $class, mixed $id): ?object => Form::class === $class && 12 === $id ? $form : null);

        return $entities;
    }

    private function conversation(bool $withContact = true): MetaConversation
    {
        $conversation = (new MetaConversation())->setChannel('email')->setRecipient('joao@example.com');
        $id = new \ReflectionProperty(MetaConversation::class, 'id');
        $id->setValue($conversation, 812);
        if ($withContact) {
            $contact = new Lead();
            $contact->setId(5);
            $conversation->setContact($contact);
        }

        return $conversation;
    }

    private function signer(string $secret = 'segredo-da-bancada'): InboxSigner
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturnCallback(static fn (string $name): mixed => match ($name) {
            'secret_key' => $secret,
            'site_url' => 'https://localhost:8443/',
            default => null,
        });

        return new InboxSigner($parameters);
    }
}
