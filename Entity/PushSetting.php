<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;

/** Uma linha de chave/valor para o que o push guarda por instalacao. */
class PushSetting extends CommonEntity
{
    private $id;
    private string $name = '';
    private string $value = '';
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('inbox_push_settings')->setCustomRepositoryClass(PushSettingRepository::class)->addUniqueConstraint(['name'], 'inbox_push_setting_name');
        $b->addId();
        $b->addField('name', Types::STRING, ['length' => 64]);
        $b->addField('value', Types::TEXT);
    }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = $v; return $this; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $v): self { $this->value = $v; return $this; }
}
