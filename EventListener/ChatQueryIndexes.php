<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\EventListener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/** Keep the indexes used by Inbox's batched message projections in schema metadata. */
final class ChatQueryIndexes
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (MetaMessage::class !== $metadata->getName()) return;
        $builder = new ClassMetadataBuilder($metadata);
        $builder->addIndex(['conversation_id', 'date_added', 'id'], 'inbox_message_latest');
        $builder->addIndex(['conversation_id', 'direction', 'date_added', 'id'], 'inbox_message_inbound');
    }
}
