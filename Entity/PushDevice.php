<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;

/**
 * A subscription of one browser of one user.
 *
 * p256dh e auth ficam **em base64url, exatamente como o navegador mandou**. Nada aqui
 * decodifica: quem envia decodifica no ultimo instante, perto da cifragem.
 */
class PushDevice extends CommonEntity
{
    public const RETIREMENT_THRESHOLD = 10;

    private $id;
    private ?User $user = null;
    private string $endpoint = '';
    private string $endpointHash = '';
    private string $p256dh = '';
    private string $auth = '';
    private ?string $userAgent = null;
    private bool $active = true;
    private int $consecutiveFailures = 0;
    private \DateTimeInterface $dateAdded;
    private ?\DateTimeInterface $lastDeliveredAt = null;
    public function __construct() { $this->dateAdded = new \DateTimeImmutable(); }
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_push_devices')->setCustomRepositoryClass(PushDeviceRepository::class)->addUniqueConstraint(['endpoint_hash'], 'inbox_push_endpoint')->addIndex(['user_id', 'active'], 'inbox_push_user_active');
        $b->addId();
        $b->createManyToOne('user', User::class)->addJoinColumn('user_id', 'id', false, false, 'CASCADE')->build();
        $b->addField('endpoint', Types::TEXT);
        $b->addField('endpointHash', Types::STRING, ['columnName' => 'endpoint_hash', 'length' => 64]);
        $b->addField('p256dh', Types::STRING, ['length' => 255]);
        $b->addField('auth', Types::STRING, ['length' => 255]);
        $b->addField('userAgent', Types::STRING, ['columnName' => 'user_agent', 'length' => 255, 'nullable' => true]);
        $b->addField('active', Types::BOOLEAN);
        $b->addField('consecutiveFailures', Types::INTEGER, ['columnName' => 'consecutive_failures']);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('lastDeliveredAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'last_delivered_at', 'nullable' => true]);
    }
    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $v): self { $this->user = $v; return $this; }
    public function getEndpoint(): string { return $this->endpoint; }
    /** O endpoint e longo demais para indexar: o hash e quem carrega a unicidade. */
    public function setEndpoint(string $v): self { $this->endpoint = $v; $this->endpointHash = hash('sha256', $v); return $this; }
    public function getEndpointHash(): string { return $this->endpointHash; }
    /** As duas chaves nao querem dizer nada separadas, entao entram juntas. */
    public function setKeys(string $p256dh, string $auth): self { $this->p256dh = $p256dh; $this->auth = $auth; return $this; }
    public function getP256dh(): string { return $this->p256dh; }
    public function getAuth(): string { return $this->auth; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): self { $this->userAgent = null === $v ? null : mb_substr($v, 0, 255); return $this; }
    public function isActive(): bool { return $this->active; }
    public function getConsecutiveFailures(): int { return $this->consecutiveFailures; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getLastDeliveredAt(): ?\DateTimeInterface { return $this->lastDeliveredAt; }
    public function recordFailure(): self { if (++$this->consecutiveFailures >= self::RETIREMENT_THRESHOLD) { $this->active = false; } return $this; }
    public function recordSuccess(): self { $this->consecutiveFailures = 0; $this->active = true; $this->lastDeliveredAt = new \DateTimeImmutable(); return $this; }
}
