<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/** @extends CommonRepository<ConversationState> */
final class ConversationStateRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'ics'; }
}
