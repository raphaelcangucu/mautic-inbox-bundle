<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Forms;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\MauticInboxBundle\Application\Email\InboxSigner;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

/**
 * O formulario de pesquisa dentro da conversa, neutro de canal.
 *
 * O atendente (ou a acao de campanha) escreve o marcador [[form:12]]; no enfileiramento ele
 * vira um link /inbox/f/{token} que identifica formulario, contato e conversa por 30 dias. O
 * mesmo marcador funciona numa resposta de WhatsApp: muda so a forma do link no texto.
 */
final class FormLinks
{
    public const VALIDITY_SECONDS = 30 * 86400;
    public const MARKER = '/\[\[form:([1-9][0-9]{0,9})\]\]/';
    private const MAX_FORMS_PER_MESSAGE = 5;

    public function __construct(private EntityManagerInterface $entityManager, private InboxSigner $signer)
    {
    }

    /** @return list<array{id:int,name:string}> */
    public function published(): array
    {
        $forms = $this->entityManager->getRepository(Form::class)->findBy(['isPublished' => true], ['name' => 'ASC'], 200);

        return array_values(array_map(
            static fn (Form $form): array => ['id' => (int) $form->getId(), 'name' => (string) $form->getName()],
            array_filter($forms, static fn (mixed $form): bool => $form instanceof Form && $form->isPublished()),
        ));
    }

    /** @return list<int> */
    public static function markers(string $body): array
    {
        preg_match_all(self::MARKER, $body, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * Troca cada marcador pelo link do contato desta conversa. Formulario despublicado ou
     * conversa sem contato recusam ANTES de enfileirar: um link quebrado no e-mail do lead nao
     * tem conserto depois que saiu.
     *
     * @param bool $markdown e-mail renderiza [Nome](url); WhatsApp nao, e recebe "Nome: url"
     */
    public function expand(string $body, MetaConversation $conversation, bool $markdown, ?int $now = null): string
    {
        $ids = self::markers($body);
        if ([] === $ids) { return $body; }
        if (count($ids) > self::MAX_FORMS_PER_MESSAGE) { throw new InboxException('mautic.inbox.ui.form_too_many', 422); }
        $contact = $conversation->getContact();
        if (null === $contact || null === $contact->getId() || null === $conversation->getId()) { throw new InboxException('mautic.inbox.ui.form_needs_contact', 422); }
        $links = [];
        foreach ($ids as $id) {
            $form = $this->entityManager->find(Form::class, $id);
            if (!$form instanceof Form || !$form->isPublished()) { throw new InboxException('mautic.inbox.ui.form_unpublished', 422); }
            $url = $this->url($id, (int) $contact->getId(), (int) $conversation->getId(), $now);
            $name = trim((string) preg_replace('/[\[\]()\r\n]+/', ' ', (string) $form->getName())) ?: 'Formulário';
            $links[$id] = $markdown ? '['.$name.']('.$url.')' : $name.': '.$url;
        }

        return (string) preg_replace_callback(self::MARKER, static fn (array $match): string => $links[(int) $match[1]], $body);
    }

    public function url(int $formId, int $contactId, int $conversationId, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::VALIDITY_SECONDS;

        return $this->signer->siteUrl().'/inbox/f/'.$this->signer->sign(InboxSigner::FORM, [$formId, $contactId, $conversationId, $expires]);
    }

    /** @return array{form:int,contact:int,conversation:int}|null */
    public function resolve(string $token, ?int $now = null): ?array
    {
        $values = $this->signer->verify(InboxSigner::FORM, $token, 4);
        if (null === $values || $values[3] < ($now ?? time())) { return null; }

        return ['form' => $values[0], 'contact' => $values[1], 'conversation' => $values[2]];
    }
}
