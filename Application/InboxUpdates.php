<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Entity\Note;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class InboxUpdates
{
    public function __construct(private EntityManagerInterface $entityManager) {}
    public function token(): string
    {
        $parts = [];
        $connection = $this->entityManager->getConnection();
        foreach ([\MauticPlugin\MauticMetaBundle\Entity\MetaConversation::class, ConversationState::class, MetaMessage::class, OutboundRequest::class, Note::class, EventLog::class] as $class) {
            $metadata = $this->entityManager->getClassMetadata($class);
            $select = ['COUNT(*) AS n', 'MAX(id) AS last_id'];
            if ($metadata->hasField('dateModified')) { $select[] = 'MAX('.$connection->quoteIdentifier($metadata->getColumnName('dateModified')).') AS modified'; }
            if ($metadata->hasField('lastMessageAt')) { $select[] = 'MAX(last_message_at) AS last_message'; }
            if ($metadata->hasField('unreadCount')) { $select[] = 'SUM(unread_count) AS unread_count'; }
            if ($metadata->hasField('version')) { $select[] = 'SUM(version) AS versions'; }
            if ($metadata->hasField('status')) { $select[] = 'SUM(CRC32(status)) AS states'; }
            $parts[] = $connection->fetchAssociative('SELECT '.implode(',', $select).' FROM '.$connection->quoteIdentifier($metadata->getTableName()));
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
