<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\UnresolvedRecipient;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class ReplyAvailability
{
    /**
     * Onde o canal por QR grava o estado do numero -- em `settings`, nunca em `status` do
     * asset, e isso e desenho e nao descuido: sair de `active` fecharia o compositor E
     * faria o retry devolver 409, quando o que se quer na queda e o oposto, compositor
     * aberto e resposta na fila ate o numero voltar.
     *
     * A chave chega por copia, e nao pela constante do plugin que a grava
     * (MauticWhatsQrBundle\Domain\SessionState::SETTING_STATUS), porque aquele plugin e
     * opcional: uma referencia de classe mataria esta caixa em toda instalacao que so tem
     * o canal homologado.
     */
    private const QR_SESSION_STATUS = 'whatsqr_session_status';

    /**
     * Os estados em que o numero so volta se alguem for ate ele: o WhatsApp desfez o
     * pareamento e so um scan novo o refaz (`logged_out`), ou a sessao morreu de vez
     * (`failed`).
     *
     * `reconnecting` fica de fora de proposito -- ali o numero volta sozinho, a resposta
     * espera na fila e sai quando ele voltar, que e o desenho inteiro deste canal. A
     * diferenca entre os dois e justamente essa: um o atendente espera, o outro precisa
     * de alguem escaneando o QR de novo, e a fila descarta a resposta em duas horas.
     *
     * Palavra que este bundle nao conhece -- inclusive nenhuma gravada -- nao fecha o
     * compositor. A varredura que expira envio faz o contrario, e faz bem: ela escolhe
     * entre "mandar agora" e "esperar", e esperar nao custa nada. Aqui a escolha e entre
     * deixar uma pessoa escrever ou cala-la, e calar por uma palavra que ninguem ensinou
     * ao plugin fecharia a caixa de um numero que pode estar perfeitamente no ar.
     */
    private const QR_NEEDS_SOMEBODY = ['logged_out', 'failed'];

    public function __construct(private EntityManagerInterface $entityManager, private \Symfony\Contracts\Translation\TranslatorInterface $translator) {}
    public function reason(ConversationState $state): ?string
    {
        $c = $state->getConversation();
        $qr = AssetType::WhatsAppQrSession === $c->getAsset()->getType();
        if ('resolved' === $state->getLifecycle()) { return $this->translator->trans('mautic.inbox.ui.this_conversation_is_resolved_reopen_it_to_reply_fd61b2'); }
        // Sem telefone nao ha para onde responder: o destinatario guarda um identificador
        // opaco de privacidade, marcado para ninguem o confundir com numero, e todo envio
        // ja nasce recusado em definitivo. Vem antes do estado do canal porque reconectar
        // o numero nao faz aparecer o telefone que o WhatsApp nunca contou.
        if (UnresolvedRecipient::marks($c->getRecipient())) { return $this->translator->trans('mautic.inbox.ui.reply_recipient_unresolved'); }
        if ('active' !== $c->getAsset()->getStatus() || !$c->getAsset()->isPublished()) { return $this->translator->trans('mautic.inbox.ui.the_channel_is_unavailable_review_the_connection_in_meta_a8f714'); }
        if ($qr && in_array((string) ($c->getAsset()->getSettings()[self::QR_SESSION_STATUS] ?? ''), self::QR_NEEDS_SOMEBODY, true)) { return $this->translator->trans('mautic.inbox.ui.reply_qr_session_disconnected'); }
        if ('facebook' === $c->getChannel() && false === ($c->getAsset()->getSettings()['facebook_reply_enabled'] ?? true)) { return $this->translator->trans('mautic.inbox.ui.the_facebook_connection_needs_additional_permissions_in_meta_befo_85248a'); }
        $repo = $this->entityManager->getRepository(MetaMessage::class);
        $comment = str_starts_with($c->getRecipient(), 'comment:');
        if ($comment && 'facebook' === $c->getChannel()) {
            $source = $repo->findOneBy(['conversation' => $c, 'direction' => 'inbound', 'messageType' => 'comment']);
            return !$source || !empty($source->getPayload()['removed']) ? $this->translator->trans('mautic.inbox.ui.this_comment_was_removed_or_is_not_available_for_a_reply_2a340b') : null;
        }
        if ($comment && ($repo->findOneBy(['conversation' => $c, 'direction' => 'outbound', 'messageType' => 'private_reply', 'status' => ['accepted', 'sent', 'delivered', 'read', 'pending', 'processing', 'uncertain']]) || $this->entityManager->getRepository(OutboundRequest::class)->findOneBy(['conversation' => $c, 'status' => ['pending', 'processing', 'waiting', 'uncertain', 'sent', 'accepted', 'delivered', 'read']]))) {
            return $this->translator->trans('mautic.inbox.ui.this_comment_already_received_a_private_reply_or_has_a_send_in_pr_a118b7');
        }
        // A janela de 24h e regra do WABA e so existe dentro dele. Uma sessao por QR nao
        // tem janela de sessao para expirar, entao daqui para baixo nao ha nada a
        // perguntar sobre ela -- nem a consulta.
        if ($qr) { return null; }
        $last = $repo->findOneBy(['conversation' => $c, 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC']);
        if ('whatsapp' === $c->getChannel() && $c->getContact()) {
            // Match the connector's window policy when Brazilian phone normalization changed the recipient.
            $last = $repo->createQueryBuilder('m')->where('m.asset = :asset AND m.channel = :channel AND m.direction = :direction')
                ->andWhere('(m.recipient = :recipient OR m.contact = :contact)')
                ->setParameters(['asset' => $c->getAsset(), 'channel' => 'whatsapp', 'direction' => 'inbound', 'recipient' => $c->getRecipient(), 'contact' => $c->getContact()])
                ->orderBy('m.dateAdded', 'DESC')->addOrderBy('m.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }
        if ($comment) {
            if (!$last || $last->getDateAdded() < new \DateTimeImmutable('-7 days')) { return $this->translator->trans('mautic.inbox.ui.the_private_reply_window_for_this_comment_has_closed_8c4819'); }
        } elseif (!$last || $last->getDateAdded() < new \DateTimeImmutable('-24 hours')) {
            return 'whatsapp' === $c->getChannel() ? $this->translator->trans('mautic.inbox.ui.no_message_was_received_in_the_last_24_hours_use_an_approved_what_bcb7ba') : ('facebook' === $c->getChannel() ? $this->translator->trans('mautic.inbox.ui.no_message_was_received_in_the_last_24_hours_wait_for_a_new_messe_7ed11e') : $this->translator->trans('mautic.inbox.ui.no_message_was_received_in_the_last_24_hours_wait_for_a_new_direc_221a2e'));
        }
        return null;
    }
}
