<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;

class CannedResponse extends CommonEntity
{
    private $id;
    private string $name = '';
    private string $body = '';
    private bool $enabled = true;
    private ?User $createdBy = null;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;
    public function __construct() { $this->dateAdded = $this->dateModified = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_canned_responses')->setCustomRepositoryClass(CannedResponseRepository::class)->addUniqueConstraint(['name'], 'inbox_canned_name')->addIndex(['enabled', 'name'], 'inbox_canned_enabled');
        $b->addId();
        $b->createManyToOne('createdBy', User::class)->addJoinColumn('created_by_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('name', Types::STRING, ['length' => 100]);
        $b->addField('body', Types::TEXT);
        $b->addField('enabled', Types::BOOLEAN);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = trim($v); $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $v): self { $this->body = trim($v); $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $v): self { $this->enabled = $v; $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $v): self { $this->createdBy = $v; return $this; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
}
