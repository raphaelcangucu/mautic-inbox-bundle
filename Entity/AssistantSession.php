<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;

/**
 * Uma conversa com o assistente, guardada no servidor em vez de no aparelho.
 *
 * Antes desta versao o app mantinha um fio unico por agente no cache da conta: desinstalar,
 * trocar de aparelho ou sair da conta apagava tudo, e nao havia como voltar a um assunto
 * especifico. Aqui cada sessao e uma linha do dono, com o contexto de onde foi aberta, e os
 * turnos ficam num JSON porque e exatamente o array que a tela ja carrega inteiro.
 */
class AssistantSession extends CommonEntity
{
    private $id;
    private ?User $user = null;
    private string $agentKey = '';
    private string $title = '';
    private ?string $contextKind = null;
    private ?int $contextId = null;
    private array $turns = [];
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
        $this->dateModified = $this->dateAdded;
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_assistant_sessions')->setCustomRepositoryClass(AssistantSessionRepository::class)->addIndex(['user_id', 'date_modified'], 'inbox_assistant_session_owner');
        $b->addId();
        $b->createManyToOne('user', User::class)->addJoinColumn('user_id', 'id', false, false, 'CASCADE')->build();
        $b->addField('agentKey', Types::STRING, ['columnName' => 'agent_key', 'length' => 100]);
        $b->addField('title', Types::STRING, ['length' => 160]);
        $b->addField('contextKind', Types::STRING, ['columnName' => 'context_kind', 'length' => 16, 'nullable' => true]);
        $b->addField('contextId', Types::INTEGER, ['columnName' => 'context_id', 'nullable' => true]);
        $b->addField('turns', Types::JSON);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $v): self { $this->user = $v; return $this; }
    public function getAgentKey(): string { return $this->agentKey; }
    public function setAgentKey(string $v): self { $this->agentKey = $v; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): self { $this->title = $v; return $this; }
    public function getContextKind(): ?string { return $this->contextKind; }
    public function getContextId(): ?int { return $this->contextId; }

    /** O contexto so se define ao abrir: retomar uma sessao nao a muda de assunto. */
    public function openedFrom(?string $kind, ?int $id): self
    {
        $this->contextKind = null !== $kind && null !== $id ? $kind : null;
        $this->contextId = null === $this->contextKind ? null : $id;

        return $this;
    }

    public function getTurns(): array { return $this->turns; }

    public function setTurns(array $turns): self
    {
        $this->turns = array_values($turns);
        $this->dateModified = new \DateTimeImmutable();

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
}
