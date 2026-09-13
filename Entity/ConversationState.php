<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

class ConversationState extends CommonEntity
{
    private $id;
    private MetaConversation $conversation;
    private ?User $assignee = null;
    private string $lifecycle = 'open';
    private bool $needsResponse = true;
    private bool $humanTakeover = false;
    private ?\DateTimeInterface $snoozedUntil = null;
    private ?int $lastInboundMessageId = null;
    private int $version = 1;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded = $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_conversation_states')->setCustomRepositoryClass(ConversationStateRepository::class)
            ->addUniqueConstraint(['conversation_id'], 'inbox_state_conversation')
            ->addIndex(['assignee_id', 'lifecycle'], 'inbox_state_queue')
            ->addIndex(['needs_response', 'lifecycle'], 'inbox_state_response')
            ->addIndex(['snoozed_until'], 'inbox_state_snooze');
        $b->addId();
        $b->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('assignee', User::class)->addJoinColumn('assignee_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('lifecycle', Types::STRING, ['length' => 24]);
        $b->addField('needsResponse', Types::BOOLEAN, ['columnName' => 'needs_response']);
        $b->addField('humanTakeover', Types::BOOLEAN, ['columnName' => 'human_takeover']);
        $b->addNullableField('snoozedUntil', Types::DATETIME_IMMUTABLE, 'snoozed_until');
        $b->addNullableField('lastInboundMessageId', Types::INTEGER, 'last_inbound_message_id');
        $b->addField('version', Types::INTEGER, ['options' => ['unsigned' => true, 'default' => 1]]);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): MetaConversation { return $this->conversation; }
    public function setConversation(MetaConversation $value): self { $this->conversation = $value; return $this; }
    public function getAssignee(): ?User { return $this->assignee; }
    public function setAssignee(?User $value): self { $this->assignee = $value; return $this->touch(); }
    public function getLifecycle(): string { return $this->lifecycle; }
    public function setLifecycle(string $value): self { $this->lifecycle = $value; return $this->touch(); }
    public function needsResponse(): bool { return $this->needsResponse; }
    public function setNeedsResponse(bool $value): self { $this->needsResponse = $value; return $this->touch(); }
    public function isHumanTakeover(): bool { return $this->humanTakeover; }
    public function setHumanTakeover(bool $value): self { $this->humanTakeover = $value; return $this->touch(); }
    public function getSnoozedUntil(): ?\DateTimeInterface { return $this->snoozedUntil; }
    public function setSnoozedUntil(?\DateTimeInterface $value): self { $this->snoozedUntil = $value; return $this->touch(); }
    public function getLastInboundMessageId(): ?int { return $this->lastInboundMessageId; }
    public function setLastInboundMessageId(?int $value): self { $this->lastInboundMessageId = $value; return $this->touch(); }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $value): self { $this->version = $value; return $this; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
    private function touch(): self { $this->dateModified = new \DateTimeImmutable(); return $this; }
}
