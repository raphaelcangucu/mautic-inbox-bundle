<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;

/**
 * Se o atendente esta aceitando conversas agora. Uma linha por usuario: a ausencia de linha
 * significa disponivel, que e o comportamento que o Inbox sempre teve, entao instalar esta
 * versao nao muda nada para quem ja usava.
 */
class OperatorPresence extends CommonEntity
{
    private $id;
    private ?User $user = null;
    private bool $available = true;
    private \DateTimeInterface $dateModified;

    public function __construct() { $this->dateModified = new \DateTimeImmutable(); }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_operator_presence')->setCustomRepositoryClass(OperatorPresenceRepository::class)->addUniqueConstraint(['user_id'], 'inbox_presence_user');
        $b->addId();
        $b->createManyToOne('user', User::class)->addJoinColumn('user_id', 'id', false, false, 'CASCADE')->build();
        $b->addField('available', Types::BOOLEAN);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $v): self { $this->user = $v; return $this; }
    public function isAvailable(): bool { return $this->available; }
    public function setAvailable(bool $v): self { $this->available = $v; $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
}
