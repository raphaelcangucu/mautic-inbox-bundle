<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

class EventLog extends CommonEntity
{
    private $id;
    private MetaConversation $conversation;
    private ?User $actor = null;
    private string $eventType = '';
    /** @var array<string, scalar|null> */
    private array $details = [];
    private \DateTimeInterface $dateAdded;
    public function __construct() { $this->dateAdded = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_event_log')->setCustomRepositoryClass(EventLogRepository::class)->addIndex(['conversation_id', 'date_added'], 'inbox_event_timeline');
        $b->addId();
        $b->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('actor', User::class)->addJoinColumn('actor_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('eventType', Types::STRING, ['columnName' => 'event_type', 'length' => 32]);
        $b->addField('details', Types::JSON);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
    }
    public function getId(): ?int { return $this->id; }
    public function setConversation(MetaConversation $v): self { $this->conversation = $v; return $this; }
    public function getConversation(): MetaConversation { return $this->conversation; }
    public function setActor(?User $v): self { $this->actor = $v; return $this; }
    public function getActor(): ?User { return $this->actor; }
    public function setEventType(string $v): self { $this->eventType = $v; return $this; }
    public function getEventType(): string { return $this->eventType; }
    /** @param array<string, scalar|null> $v */
    public function setDetails(array $v): self { $this->details = $v; return $this; }
    /** @return array<string, scalar|null> */
    public function getDetails(): array { return $this->details; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
}
