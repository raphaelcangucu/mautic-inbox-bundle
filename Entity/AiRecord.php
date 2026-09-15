<?php
namespace MauticPlugin\MauticInboxBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
/** Versioned records for AI configuration, documents and execution state. */
class AiRecord extends CommonEntity
{
    private $id;
    private string $kind = '';
    private string $recordKey = '';
    private array $data = [];
    private int $revision = 1;
    public static function loadMetadata(ORM\ClassMetadata $metadata): void {
        $b=new ClassMetadataBuilder($metadata);$b->setTable('inbox_ai_records')->addUniqueConstraint(['kind','record_key'],'inbox_ai_record_key');$b->addId();
        $b->addField('kind',Types::STRING,['length'=>24]);$b->addField('recordKey',Types::STRING,['columnName'=>'record_key','length'=>100]);$b->addField('data',Types::JSON);$b->addField('revision',Types::INTEGER);
    }
    public function getId(): ?int{return $this->id;}
    public function getKind(): string{return $this->kind;}
    public function getRecordKey(): string{return $this->recordKey;}
    public function getData(): array{return $this->data;}
    public function getRevision(): int{return $this->revision;}
    public function initialize(string $kind,string $key): self{$this->kind=$kind;$this->recordKey=$key;return $this;}
    public function change(array $data): self{$this->data=$data;++$this->revision;return $this;}
}
