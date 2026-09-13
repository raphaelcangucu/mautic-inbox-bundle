<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

class OutboundRequest extends CommonEntity
{
    private $id;
    private MetaConversation $conversation;
    private User $author;
    private ?MetaOutboundJob $job = null;
    private string $requestId = '';
    private string $body = '';
    private string $status = 'pending';
    private ?string $failureReason = null;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;
    public function __construct() { $this->dateAdded = $this->dateModified = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_outbound_requests')->setCustomRepositoryClass(OutboundRequestRepository::class)->addUniqueConstraint(['request_id'], 'inbox_outbound_request_id')->addUniqueConstraint(['job_id'], 'inbox_outbound_job')->addIndex(['conversation_id', 'date_added'], 'inbox_outbound_timeline');
        $b->addId();
        $b->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('author', User::class)->addJoinColumn('author_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('job', MetaOutboundJob::class)->addJoinColumn('job_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('requestId', Types::STRING, ['columnName' => 'request_id', 'length' => 64]);
        $b->addField('body', Types::TEXT);
        $b->addField('status', Types::STRING, ['length' => 24]);
        $b->addNullableField('failureReason', Types::STRING, 'failure_reason');
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }
    public function getId(): ?int { return $this->id; }
    public function setConversation(MetaConversation $v): self { $this->conversation = $v; return $this; }
    public function getConversation(): MetaConversation { return $this->conversation; }
    public function setAuthor(User $v): self { $this->author = $v; return $this; }
    public function getAuthor(): User { return $this->author; }
    public function setJob(?MetaOutboundJob $v): self { $this->job = $v; return $this; }
    public function getJob(): ?MetaOutboundJob { return $this->job; }
    public function setRequestId(string $v): self { $this->requestId = $v; return $this; }
    public function getRequestId(): string { return $this->requestId; }
    public function setBody(string $v): self { $this->body = $v; return $this; }
    public function getBody(): string { return $this->body; }
    public function setStatus(string $v): self { $this->status = $v; $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setFailureReason(?string $v): self { $this->failureReason = $v; return $this; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
}
