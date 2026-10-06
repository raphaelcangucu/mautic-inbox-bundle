<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Contract;

use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

interface ParticipantAvatarProviderInterface
{
    // Build a protected local URL from the already-loaded conversation; no I/O.
    public function avatarUrl(MetaConversation $conversation): ?string;
}
