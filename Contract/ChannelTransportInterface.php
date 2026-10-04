<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Contract;

use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

/**
 * Extension point for channels delivered outside the Meta outbound queue.
 *
 * Providers own their durable delivery record and realtime transport. The
 * Inbox keeps assignment, drafts, notes, AI and the operator experience.
 */
interface ChannelTransportInterface
{
    public function supports(MetaConversation $conversation): bool;

    public function replyBlockedReason(ConversationState $state): ?string;

    public function sendHuman(ConversationState $state, OutboundRequest $request): void;

    /** @param array<string,mixed> $metadata */
    public function sendAi(ConversationState $state, string $body, array $metadata): void;

    /** @return array<string,mixed> Values override the standard Inbox summary. */
    public function conversationMetadata(ConversationState $state): array;

    public function markRead(ConversationState $state): void;
}
