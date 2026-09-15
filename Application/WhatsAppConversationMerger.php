<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

final class WhatsAppConversationMerger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PhoneNormalizer $phones,
    ) {
    }

    /**
     * @return list<array{asset_id:int,canonical_recipient:string,conversation_ids:list<int>,contact_ids:list<int>,safe:bool}>
     */
    public function duplicateGroups(?MetaAsset $asset = null): array
    {
        $criteria = ['channel' => 'whatsapp'];
        if ($asset instanceof MetaAsset) {
            $criteria['asset'] = $asset;
        }
        $groups = [];
        foreach ($this->entityManager->getRepository(MetaConversation::class)->findBy($criteria, ['id' => 'ASC']) as $conversation) {
            if (!$conversation instanceof MetaConversation || null === $conversation->getId()) {
                continue;
            }
            $region = (string) ($conversation->getAsset()->getSettings()['default_region'] ?? 'BR');
            $canonical = $this->phones->equivalentRecipients($conversation->getRecipient(), $region)[0] ?? $conversation->getRecipient();
            $key = $conversation->getAsset()->getId().':'.$canonical;
            $groups[$key] ??= [
                'asset_id' => (int) $conversation->getAsset()->getId(),
                'canonical_recipient' => $canonical,
                'conversation_ids' => [],
                'contact_ids' => [],
            ];
            $groups[$key]['conversation_ids'][] = (int) $conversation->getId();
            if (null !== $conversation->getContact()?->getId()) {
                $groups[$key]['contact_ids'][] = (int) $conversation->getContact()->getId();
            }
        }

        $duplicates = [];
        foreach ($groups as $group) {
            $group['conversation_ids'] = array_values(array_unique($group['conversation_ids']));
            $group['contact_ids'] = array_values(array_unique($group['contact_ids']));
            if (count($group['conversation_ids']) < 2) {
                continue;
            }
            $group['safe'] = count($group['contact_ids']) <= 1;
            $duplicates[] = $group;
        }

        return $duplicates;
    }

    /**
     * @param list<int> $conversationIds
     *
     * @return array{primary_conversation_id:int,merged_conversation_ids:list<int>,state_id:int|null,messages:int}
     */
    public function merge(array $conversationIds, string $canonicalRecipient): array
    {
        $conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));
        sort($conversationIds);
        if (count($conversationIds) < 2 || '' === trim($canonicalRecipient)) {
            throw new \InvalidArgumentException('At least two conversations and one canonical recipient are required.');
        }

        $connection = $this->entityManager->getConnection();
        $tables = [
            'conversations' => $this->table('meta_conversations'),
            'messages' => $this->table('meta_messages'),
            'states' => $this->table('inbox_conversation_states'),
            'ai' => $this->table('inbox_ai_records'),
            'jobs' => $this->table('meta_outbound_jobs'),
            'notes' => $this->table('inbox_notes'),
            'events' => $this->table('inbox_event_log'),
            'outbound' => $this->table('inbox_outbound_requests'),
            'comments' => $this->table('inbox_comment_contexts'),
        ];
        $result = $connection->transactional(function (Connection $db) use ($conversationIds, $canonicalRecipient, $tables): array {
            $rows = $db->executeQuery(
                sprintf(
                    'SELECT id, asset_id, channel, recipient, contact_id, status, unread_count, date_added, last_message_at, last_inbound_at
                       FROM %s WHERE id IN (:ids) ORDER BY id ASC FOR UPDATE',
                    $tables['conversations'],
                ),
                ['ids' => $conversationIds],
                ['ids' => ArrayParameterType::INTEGER],
            )->fetchAllAssociative();
            if (count($rows) !== count($conversationIds)) {
                throw new \RuntimeException('One or more conversations no longer exist.');
            }
            $assetIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['asset_id'], $rows)));
            $channels = array_values(array_unique(array_column($rows, 'channel')));
            $contacts = array_values(array_unique(array_filter(array_map(static fn (array $row): ?int => null === $row['contact_id'] ? null : (int) $row['contact_id'], $rows))));
            if (1 !== count($assetIds) || ['whatsapp'] !== $channels || count($contacts) > 1) {
                throw new \RuntimeException('Conversations do not belong to one safe WhatsApp identity.');
            }

            $primaryId = (int) $rows[0]['id'];
            $mergedIds = array_values(array_filter($conversationIds, static fn (int $id): bool => $id !== $primaryId));
            $contactId = $contacts[0] ?? null;
            $stateRows = $db->executeQuery(
                sprintf("SELECT s.*, CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS has_ai
                   FROM %s s
              LEFT JOIN %s a
                     ON a.kind = 'assignment' AND BINARY a.record_key = BINARY CAST(s.id AS CHAR)
                  WHERE s.conversation_id IN (:ids)
               ORDER BY has_ai DESC, s.human_takeover DESC, (s.assignee_id IS NOT NULL) DESC, s.date_modified DESC, s.id ASC", $tables['states'], $tables['ai']),
                ['ids' => $conversationIds],
                ['ids' => ArrayParameterType::INTEGER],
            )->fetchAllAssociative();
            if ((int) $db->fetchOne(
                sprintf("SELECT COUNT(*) FROM %s a
                  JOIN %s s ON a.kind = 'assignment' AND BINARY a.record_key = BINARY CAST(s.id AS CHAR)
                 WHERE s.conversation_id IN (:ids)", $tables['ai'], $tables['states']),
                ['ids' => $conversationIds],
                ['ids' => ArrayParameterType::INTEGER],
            ) > 1) {
                throw new \RuntimeException('More than one AI assignment exists; manual review is required.');
            }

            $keepStateId = null;
            if ([] !== $stateRows) {
                $keepState = $stateRows[0];
                $keepStateId = (int) $keepState['id'];
                $stateIds = array_map(static fn (array $row): int => (int) $row['id'], $stateRows);
                foreach ($stateIds as $stateId) {
                    if ($stateId === $keepStateId) {
                        continue;
                    }
                    $db->executeStatement(
                        sprintf("UPDATE %s
                            SET record_key = CONCAT(:keep, SUBSTRING(record_key, LENGTH(:drop) + 1))
                          WHERE kind = 'run' AND record_key LIKE :prefix", $tables['ai']),
                        ['keep' => (string) $keepStateId, 'drop' => (string) $stateId, 'prefix' => $stateId.':%'],
                    );
                    $db->executeStatement(
                        sprintf("UPDATE %s SET payload = JSON_SET(payload, '$._ai_state', :keep)
                          WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$._ai_state')) = :drop", $tables['jobs']),
                        ['keep' => $keepStateId, 'drop' => (string) $stateId],
                    );
                }
                $needsResponse = max(array_map(static fn (array $row): int => (int) $row['needs_response'], $stateRows));
                $humanTakeover = max(array_map(static fn (array $row): int => (int) $row['human_takeover'], $stateRows));
                $lastInbound = max(array_map(static fn (array $row): int => (int) ($row['last_inbound_message_id'] ?? 0), $stateRows)) ?: null;
                $version = max(array_map(static fn (array $row): int => (int) $row['version'], $stateRows)) + 1;
                $assigneeId = $keepState['assignee_id'];
                if (null === $assigneeId) {
                    foreach ($stateRows as $stateRow) {
                        if (null !== $stateRow['assignee_id']) {
                            $assigneeId = $stateRow['assignee_id'];
                            break;
                        }
                    }
                }
                $lifecycle = in_array('open', array_column($stateRows, 'lifecycle'), true) ? 'open' : (string) $keepState['lifecycle'];
                $db->executeStatement(
                    sprintf('DELETE FROM %s WHERE id IN (:ids) AND id <> :keep', $tables['states']),
                    ['ids' => $stateIds, 'keep' => $keepStateId],
                    ['ids' => ArrayParameterType::INTEGER],
                );
                $db->executeStatement(
                    sprintf('UPDATE %s
                        SET conversation_id = :conversation, lifecycle = :lifecycle, needs_response = :needs,
                            human_takeover = :takeover, snoozed_until = :snoozed, assignee_id = :assignee,
                            last_inbound_message_id = :last_inbound, version = :version, date_modified = UTC_TIMESTAMP()
                      WHERE id = :id', $tables['states']),
                    [
                        'conversation' => $primaryId,
                        'lifecycle' => $lifecycle,
                        'needs' => $needsResponse,
                        'takeover' => $humanTakeover,
                        'snoozed' => 'open' === $lifecycle ? null : $keepState['snoozed_until'],
                        'assignee' => $assigneeId,
                        'last_inbound' => $lastInbound,
                        'version' => $version,
                        'id' => $keepStateId,
                    ],
                );
            }

            $this->mergeDrafts($db, $primaryId, $mergedIds);
            foreach ([$tables['notes'], $tables['events'], $tables['outbound']] as $table) {
                $db->executeStatement(
                    sprintf('UPDATE %s SET conversation_id = :primary WHERE conversation_id IN (:merged)', $table),
                    ['primary' => $primaryId, 'merged' => $mergedIds],
                    ['merged' => ArrayParameterType::INTEGER],
                );
            }
            foreach (['public_conversation_id', 'private_conversation_id'] as $column) {
                $db->executeStatement(
                    sprintf('UPDATE %s SET %s = :primary WHERE %s IN (:merged)', $tables['comments'], $column, $column),
                    ['primary' => $primaryId, 'merged' => $mergedIds],
                    ['merged' => ArrayParameterType::INTEGER],
                );
            }
            $messageCount = $db->executeStatement(
                sprintf('UPDATE %s
                    SET conversation_id = :primary, contact_id = COALESCE(contact_id, :contact)
                  WHERE conversation_id IN (:merged)', $tables['messages']),
                ['primary' => $primaryId, 'contact' => $contactId, 'merged' => $mergedIds],
                ['merged' => ArrayParameterType::INTEGER],
            );
            foreach ($mergedIds as $mergedId) {
                $db->executeStatement(
                    sprintf("UPDATE %s SET payload = JSON_SET(payload, '$._inbox_conversation_id', :primary)
                      WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$._inbox_conversation_id')) = :merged", $tables['jobs']),
                    ['primary' => $primaryId, 'merged' => (string) $mergedId],
                );
            }
            $db->executeStatement(
                sprintf('DELETE FROM %s WHERE id IN (:merged)', $tables['conversations']),
                ['merged' => $mergedIds],
                ['merged' => ArrayParameterType::INTEGER],
            );

            $latestMessage = max(array_column($rows, 'last_message_at'));
            $inboundDates = array_values(array_filter(array_column($rows, 'last_inbound_at')));
            $lastInbound = [] === $inboundDates ? null : max($inboundDates);
            $dateAdded = min(array_column($rows, 'date_added'));
            $unread = array_sum(array_map(static fn (array $row): int => (int) $row['unread_count'], $rows));
            $status = in_array('open', array_column($rows, 'status'), true) ? 'open' : (string) $rows[0]['status'];
            $db->executeStatement(
                sprintf('UPDATE %s
                    SET recipient = :recipient, contact_id = :contact, status = :status, unread_count = :unread,
                        date_added = :date_added, last_message_at = :last_message, last_inbound_at = :last_inbound
                  WHERE id = :id', $tables['conversations']),
                [
                    'recipient' => $canonicalRecipient,
                    'contact' => $contactId,
                    'status' => $status,
                    'unread' => $unread,
                    'date_added' => $dateAdded,
                    'last_message' => $latestMessage,
                    'last_inbound' => $lastInbound,
                    'id' => $primaryId,
                ],
            );
            $db->insert($tables['events'], [
                'conversation_id' => $primaryId,
                'actor_id' => null,
                'event_type' => 'conversation_merged',
                'details' => json_encode(['merged_conversation_ids' => $mergedIds], JSON_THROW_ON_ERROR),
                'date_added' => gmdate('Y-m-d H:i:s'),
            ]);

            return [
                'primary_conversation_id' => $primaryId,
                'merged_conversation_ids' => $mergedIds,
                'state_id' => $keepStateId,
                'messages' => $messageCount,
            ];
        });
        $this->entityManager->clear();

        return $result;
    }

    /** @param list<int> $mergedIds */
    private function mergeDrafts(Connection $db, int $primaryId, array $mergedIds): void
    {
        $table = $this->table('inbox_drafts');
        $drafts = $db->executeQuery(
            sprintf('SELECT id, conversation_id, user_id, mode, body, date_modified
               FROM %s WHERE conversation_id IN (:ids)
           ORDER BY date_modified DESC, id DESC', $table),
            ['ids' => array_merge([$primaryId], $mergedIds)],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        $kept = [];
        foreach ($drafts as $draft) {
            $key = $draft['user_id'].':'.$draft['mode'];
            if (!isset($kept[$key])) {
                $kept[$key] = (int) $draft['id'];
                $db->update($table, ['conversation_id' => $primaryId], ['id' => (int) $draft['id']]);
                continue;
            }
            $db->delete($table, ['id' => (int) $draft['id']]);
        }
    }

    private function table(string $name): string
    {
        return (defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').$name;
    }
}
