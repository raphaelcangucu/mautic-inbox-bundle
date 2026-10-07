<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Mobile;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\{CoreParametersHelper,EncryptionHelper};
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset,MetaAssetRepository};
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use MauticPlugin\MauticWhatsQrBundle\Driver\SessionDriverFactory;
use MauticPlugin\MauticWhatsQrBundle\Application\PairingScreen;
use MauticPlugin\MauticInboxBundle\Application\Mobile\QrPairing;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
final class QrRecoveryTest extends TestCase {
 private function subject(string $status,bool $down=false):QrPairing {
  $encryption=$this->createMock(EncryptionHelper::class);$encryption->method('encrypt')->willReturnCallback(fn($v)=>'sealed:'.base64_encode((string)$v));$encryption->method('decrypt')->willReturnCallback(fn($v)=>base64_decode(substr((string)$v,7)));
  $parameters=$this->createMock(CoreParametersHelper::class);$parameters->method('get')->willReturnCallback(fn($k,$default=null)=>$default);
  $http=new MockHttpClient(function(string $method,string $url)use($status,$down){self::assertSame('GET',$method,'Diagnosis must never close or reset a session');self::assertStringEndsWith('/health',$url);return new MockResponse(json_encode($down?['error'=>'internal detail must stay private']:['sessions'=>[['id'=>'session-16','status'=>$status,'jid'=>'unit@jid'],['id'=>'other','status'=>'connected']]]),['http_code'=>$down?503:200]);});
  $factory=new SessionDriverFactory($http,new CredentialVault($encryption),$parameters);
  $asset=(new MetaAsset(16))->setType(AssetType::WhatsAppQrSession)->setName('Support')->setPhoneNumber('+5511999999999')->setExternalId('session-16')->setStatus('active')->setIsPublished(true);
  $factory->configure($asset,'whatsmeow','http://127.0.0.1:8090','unit-token','unit-secret');$asset->setSettings([...$asset->getSettings(),'whatsqr_session_status'=>$status]);
  $permissions=$this->createMock(CorePermissions::class);$permissions->method('isGranted')->willReturn(true);
  $repo=$this->createMock(MetaAssetRepository::class);$repo->method('findBy')->willReturn([$asset]);
  $em=$this->createMock(EntityManagerInterface::class);$em->method('find')->willReturn($asset);$em->method('getRepository')->willReturn($repo);$em->expects(self::never())->method('persist');$em->expects(self::never())->method('flush');
  return new QrPairing($em,$permissions,$factory,new PairingScreen());
 }
 public function testCatalogueShowsNumberAndRecordedConnectionStatus():void{$item=$this->subject('connected')->connections()['items'][0];self::assertSame('+5511999999999',$item['phone']);self::assertSame('connected',$item['status']);}
 public function testOnlyLostPairingOffersANewQr():void{
  foreach(['connected','reconnecting','logged_out'] as $state){$r=$this->subject($state)->status(16);self::assertSame('logged_out'===$state?'not_done':$state,$r['stage']);self::assertSame('logged_out'===$state,$r['can_regenerate']);self::assertNull($r['image_base64']);}
 }
 public function testServiceOutageIsReportedWithoutExposingDetailsOrResettingPairing():void{$r=$this->subject('connected',true)->status(16);self::assertSame('service_down',$r['cause']);self::assertFalse($r['can_start']);self::assertFalse($r['can_regenerate']);self::assertNull($r['image_base64']);self::assertStringNotContainsString('internal detail',json_encode($r));}
 public function testTemporaryReconnectCannotEraseExistingPairing():void{$this->expectException(InboxException::class);$this->subject('reconnecting')->start(16,true);}
}
