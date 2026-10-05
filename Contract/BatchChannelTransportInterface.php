<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Contract;

use MauticPlugin\MauticInboxBundle\Entity\ConversationState;

/** Optional prefetch hook: providers may assemble list metadata without per-row queries. */
interface BatchChannelTransportInterface
{
    /** @param list<ConversationState> $states */
    public function warmConversationMetadata(array $states): void;
}
