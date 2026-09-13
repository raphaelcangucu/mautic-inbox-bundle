<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

class Draft extends CommonEntity
{
    private $id;
    private MetaConversation $conversation;
    private User $user;
    private string $mode = 'reply';
    private string $body = '';
    private \DateTimeInterface $dateModified;
    public function __construct() { $this->dateModified = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_drafts')->setCustomRepositoryClass(DraftRepository::class)->addUniqueConstraint(['conversation_id', 'user_id', 'mode'], 'inbox_draft_identity');
        $b->addId();
        $b->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('user', User::class)->addJoinColumn('user_id', 'id', false, false, 'CASCADE')->build();
        $b->addField('mode', Types::STRING, ['length' => 16]);
        $b->addField('body', Types::TEXT);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }
    public function getId(): ?int { return $this->id; }
    public function setConversation(MetaConversation $v): self { $this->conversation = $v; return $this; }
    public function getConversation(): MetaConversation { return $this->conversation; }
    public function setUser(User $v): self { $this->user = $v; return $this; }
    public function getUser(): User { return $this->user; }
    public function setMode(string $v): self { $this->mode = $v; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setBody(string $v): self { $this->body = $v; $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function getBody(): string { return $this->body; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
}
