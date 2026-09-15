<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class ReplyAvailability
{
    public function __construct(private EntityManagerInterface $entityManager, private \Symfony\Contracts\Translation\TranslatorInterface $translator) {}
    public function reason(ConversationState $state): ?string
    {
        $c = $state->getConversation();
        if ('resolved' === $state->getLifecycle()) { return $this->translator->trans('mautic.inbox.ui.this_conversation_is_resolved_reopen_it_to_reply_fd61b2'); }
        if ('active' !== $c->getAsset()->getStatus() || !$c->getAsset()->isPublished()) { return $this->translator->trans('mautic.inbox.ui.the_channel_is_unavailable_review_the_connection_in_meta_a8f714'); }
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
