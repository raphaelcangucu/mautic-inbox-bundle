<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Mautic\LeadBundle\Entity\DoNotContact as Entry;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\UserBundle\Entity\User;

/**
 * O "não enviar e-mails" do contato, que é o DoNotContact do Mautic no canal de e-mail.
 *
 * Vale para campanha e para resposta humana, porque é o mesmo registro que as duas olham. Não
 * toca em WhatsApp, Instagram nem Facebook: o DNC do Mautic é por canal, e prometer silêncio
 * numa conversa que o próprio contato abriu seria mentira.
 *
 * O que o contato pediu (descadastro) e o que o servidor respondeu (retorno permanente) chegam
 * como `editable: false`. Liberar remove só o registro MANUAL, então um descadastro sobrevive a
 * um operador que desligue a chave: o pedido de quem recebe não é do atendente para desfazer.
 */
final class ContactDoNotContact
{
    public function __construct(private ?DncModel $doNotContact = null)
    {
    }

    /** Se esta instalação do Mautic tem o DoNotContact. Sem ele não há o que ler nem gravar. */
    public function available(): bool
    {
        return null !== $this->doNotContact;
    }

    /**
     * @return array{blocked: bool, reason: string|null, editable: bool}
     */
    public function state(?Lead $contact): array
    {
        if (null === $contact || null === $this->doNotContact) {
            return ['blocked' => false, 'reason' => null, 'editable' => false];
        }
        $reasons = [];
        foreach ($contact->getDoNotContact() as $entry) {
            if ('email' === $entry->getChannel()) { $reasons[] = (int) $entry->getReason(); }
        }
        $blocked = Entry::IS_CONTACTABLE !== $this->doNotContact->isContactable($contact, 'email');
        $theirs = in_array(Entry::UNSUBSCRIBED, $reasons, true);
        $bounced = in_array(Entry::BOUNCED, $reasons, true);

        return [
            'blocked' => $blocked,
            'reason' => $theirs ? 'unsubscribed' : ($bounced ? 'bounced' : ($blocked ? 'manual' : null)),
            'editable' => !$theirs && !$bounced,
        ];
    }

    /**
     * @return array{blocked: bool, reason: string|null, editable: bool}
     */
    public function set(Lead $contact, bool $blocked, User $actor): array
    {
        if (null === $this->doNotContact) {
            throw new InboxException('mautic.inbox.ui.dnc_unavailable', 422);
        }
        $state = $this->state($contact);
        if (!$state['editable']) {
            throw new InboxException('bounced' === $state['reason'] ? 'mautic.inbox.ui.dnc_bounced' : 'mautic.inbox.ui.dnc_unsubscribed', 422);
        }
        if ($blocked) {
            $this->doNotContact->addDncForContact($contact, 'email', Entry::MANUAL, 'Inbox: '.$actor->getName());
        } else {
            // Só o registro manual sai. UNSUBSCRIBED e BOUNCED continuam, e voltam como não editáveis.
            $this->doNotContact->removeDncForContact($contact, 'email', true, Entry::MANUAL);
        }

        return $this->state($contact);
    }
}
