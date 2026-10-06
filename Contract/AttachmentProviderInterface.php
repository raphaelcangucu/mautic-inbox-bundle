<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Contract;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/** URL generation only: providers must perform no network or database IO here. */
interface AttachmentProviderInterface
{
    public function attachmentUrl(MetaMessage $message, string $type): ?string;
}
