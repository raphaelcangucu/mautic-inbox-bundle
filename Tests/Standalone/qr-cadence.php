<?php
declare(strict_types=1);
// Pure isolated dependency doubles. Never loads a Mautic kernel or real connection.
namespace Doctrine\DBAL { class Connection { public array $values; public int $queries=0; public function __construct(array $values){$this->values=$values;} public function fetchOne(string $sql,array $params): int {$this->queries++;if(!$this->values)throw new \RuntimeException('Unexpected query');return array_shift($this->values);} } }
namespace MauticPlugin\MauticMetaBundle\Domain { enum AssetType { case WhatsAppQrSession; case WhatsAppPhoneNumber; } }
namespace MauticPlugin\MauticMetaBundle\Entity { class MetaAsset { public function __construct(private \MauticPlugin\MauticMetaBundle\Domain\AssetType $type,private array $settings=[]){ } public function getSettings():array{return $this->settings;} public function getType():\MauticPlugin\MauticMetaBundle\Domain\AssetType{return $this->type;}public function getId():int{return 16;} } }
namespace {
use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
require $argv[1] ?? throw new RuntimeException('Supply the patched policy file');
function check(bool $value):void{if(!$value)throw new RuntimeException('QR cadence regression');}
$connection=new Connection([0,0]);$policy=new OutboundPolicy($connection);
$policy->assertAllowed(new MetaAsset(AssetType::WhatsAppQrSession),'whatsapp','15550000001','text',null,false,true);
check($connection->queries===2);
foreach([[AssetType::WhatsAppQrSession,false],[AssetType::WhatsAppPhoneNumber,true]]as[$type,$manual]){
 $connection=new Connection([0,0,0,1]);$policy=new OutboundPolicy($connection);
 try{$policy->assertAllowed(new MetaAsset($type),'whatsapp','15550000001','text',null,false,$manual);throw new RuntimeException('Cooldown was not retained');}catch(DomainException $e){check(str_contains($e->getMessage(),'cooldown'));}
}
foreach([[1],[0,1]]as$values){
 $policy=new OutboundPolicy(new Connection($values));
 try{$policy->assertAllowed(new MetaAsset(AssetType::WhatsAppQrSession,['daily_send_limit'=>1,'hourly_send_limit'=>1]),'whatsapp','15550000001','text',null,false,true);throw new RuntimeException('Asset limit was not retained');}catch(DomainException $e){check(str_contains($e->getMessage(),'limit reached'));}
}
echo "5 QR cadence cases passed; only mock connections, no database or kernel.\n";
}
