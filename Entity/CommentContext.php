<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/** Immutable source context for a public Instagram comment. */
class CommentContext extends CommonEntity
{
    private $id;
    private MetaMessage $message;
    private MetaConversation $publicConversation;
    private ?MetaConversation $privateConversation = null;
    private string $accountId = '';
    private string $mediaId = '';
    private string $commentId = '';
    private string $participantId = '';
    private ?string $permalink = null;
    private \DateTimeInterface $dateAdded;
    public function __construct() { $this->dateAdded = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_comment_contexts')->setCustomRepositoryClass(CommentContextRepository::class)
            ->addUniqueConstraint(['message_id'], 'inbox_comment_message')->addUniqueConstraint(['account_id', 'comment_id'], 'inbox_comment_identity')
            ->addIndex(['public_conversation_id', 'date_added'], 'inbox_comment_public');
        $b->addId();
        $b->createManyToOne('message', MetaMessage::class)->addJoinColumn('message_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('publicConversation', MetaConversation::class)->addJoinColumn('public_conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('privateConversation', MetaConversation::class)->addJoinColumn('private_conversation_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('accountId', Types::STRING, ['columnName' => 'account_id', 'length' => 191]);
        $b->addField('mediaId', Types::STRING, ['columnName' => 'media_id', 'length' => 191]);
        $b->addField('commentId', Types::STRING, ['columnName' => 'comment_id', 'length' => 191]);
        $b->addField('participantId', Types::STRING, ['columnName' => 'participant_id', 'length' => 191]);
        $b->addNullableField('permalink', Types::STRING);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
    }
    public function getId(): ?int { return $this->id; }
    public function setMessage(MetaMessage $v): self { $this->message = $v; return $this; }
    public function getMessage(): MetaMessage { return $this->message; }
    public function setPublicConversation(MetaConversation $v): self { $this->publicConversation = $v; return $this; }
    public function getPublicConversation(): MetaConversation { return $this->publicConversation; }
    public function setPrivateConversation(?MetaConversation $v): self { $this->privateConversation = $v; return $this; }
    public function getPrivateConversation(): ?MetaConversation { return $this->privateConversation; }
    public function setAccountId(string $v): self { if ('' !== $this->accountId && $v !== $this->accountId) { throw new \LogicException('Comment context is immutable.'); } $this->accountId = $v; return $this; }
    public function getAccountId(): string { return $this->accountId; }
    public function setMediaId(string $v): self { if ('' !== $this->mediaId && $v !== $this->mediaId) { throw new \LogicException('Comment context is immutable.'); } $this->mediaId = $v; return $this; }
    public function getMediaId(): string { return $this->mediaId; }
    public function setCommentId(string $v): self { if ('' !== $this->commentId && $v !== $this->commentId) { throw new \LogicException('Comment context is immutable.'); } $this->commentId = $v; return $this; }
    public function getCommentId(): string { return $this->commentId; }
    public function setParticipantId(string $v): self { if ('' !== $this->participantId && $v !== $this->participantId) { throw new \LogicException('Comment context is immutable.'); } $this->participantId = $v; return $this; }
    public function getParticipantId(): string { return $this->participantId; }
    public function setPermalink(?string $v): self { if (null !== $this->permalink && $v !== $this->permalink) { throw new \LogicException('Comment context is immutable.'); } $this->permalink = $v; return $this; }
    public function getPermalink(): ?string { return $this->permalink; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
}
