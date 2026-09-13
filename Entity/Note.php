<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

class Note extends CommonEntity
{
    private $id;
    private MetaConversation $conversation;
    private User $author;
    private string $body = '';
    private \DateTimeInterface $dateAdded;

    public function __construct() { $this->dateAdded = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_notes')->setCustomRepositoryClass(NoteRepository::class)->addIndex(['conversation_id', 'date_added'], 'inbox_note_timeline');
        $b->addId();
        $b->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('author', User::class)->addJoinColumn('author_id', 'id', false, false, 'CASCADE')->build();
        $b->addField('body', Types::TEXT);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
    }
    public function getId(): ?int { return $this->id; }
    public function getConversation(): MetaConversation { return $this->conversation; }
    public function setConversation(MetaConversation $value): self { $this->conversation = $value; return $this; }
    public function getAuthor(): User { return $this->author; }
    public function setAuthor(User $value): self { $this->author = $value; return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $value): self { $this->body = trim($value); return $this; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
}
