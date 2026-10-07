<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Mobile;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Mobile\QrPairing;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use MauticPlugin\MauticWhatsQrBundle\Driver\SessionDriverFactory;
use MauticPlugin\MauticWhatsQrBundle\Application\PairingScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
final class QrCreationTest extends TestCase {
 private function subject(bool $edit=true,?MetaAsset $existing=null,?MetaAsset $duplicate=null):array {
  $encryption=$this->createMock(EncryptionHelper::class);$encryption->method('encrypt')->willReturnCallback(fn($v)=>'sealed:'.base64_encode((string)$v));$encryption->method('decrypt')->willReturnCallback(fn($v)=>base64_decode(substr((string)$v,7)));
  $parameters=$this->createMock(CoreParametersHelper::class);$parameters->method('get')->willReturnCallback(fn($k,$default=null)=>$default);
  $http=new MockHttpClient(function(){throw new \LogicException('Creation must not call any HTTP service');});
  $factory=new SessionDriverFactory($http,new CredentialVault($encryption),$parameters);
  $source=(new MetaAsset(16))->setConnection(new MetaConnection(3))->setName('Existing QR')->setExternalId('original-session')->setType(AssetType::WhatsAppQrSession)->setStatus('active')->setIsPublished(true);
  $factory->configure($source,'whatsmeow','http://127.0.0.1:8090','unit-token','unit-original-webhook');
  $source->setSettings([...$source->getSettings(),'whatsqr_session_status'=>'connected','whatsqr_session_jid'=>'original@jid']);
  $permissions=$this->createMock(CorePermissions::class);$permissions->method('isGranted')->willReturnCallback(fn($p)=>$p==='meta:connections:view'||($edit&&$p==='meta:connections:edit'));
  $repository=$this->createMock(MetaAssetRepository::class);$repository->method('findOneBy')->willReturnCallback(fn($c)=>isset($c['externalId'])?$existing:$duplicate);
  $em=$this->createMock(EntityManagerInterface::class);$em->method('find')->willReturn($source);$em->method('getRepository')->willReturn($repository);
  $user=$this->createMock(User::class);$user->method('getId')->willReturn(9);
  return [new QrPairing($em,$permissions,$factory,new PairingScreen()),$em,$source,$user];
 }
 private function payload():array{return ['name'=>'New number','phone_number'=>'+55 (11) 99999-9999','profile_id'=>16,'request_id'=>'12345678-1234-4123-8123-123456789012'];}
 public function testNewNumberHasIndependentSealedSecretAndLeavesExistingSessionUntouched():void{
  [$pairing,$em,$source,$user]=$this->subject();$before=$source->getSettings();$new=null;
  $em->expects(self::once())->method('persist')->willReturnCallback(function($asset)use(&$new){$new=$asset;});$em->expects(self::once())->method('flush');
  $response=$pairing->create($this->payload(),$user);self::assertTrue($response['can_pair']);self::assertSame($before,$source->getSettings());self::assertNotSame($source->getExternalId(),$new->getExternalId());self::assertSame('+5511999999999',$new->getPhoneNumber());self::assertSame('unknown',$new->getSettings()['whatsqr_session_status']);self::assertArrayNotHasKey('whatsqr_session_jid',$new->getSettings());self::assertNotSame($before['whatsqr_webhook_secret'],$new->getSettings()['whatsqr_webhook_secret']);self::assertStringNotContainsString('token',json_encode($response));
 }
 public function testPermissionRefusalOccursBeforeAnyWrite():void{[$p,$em,$source,$user]=$this->subject(false);$em->expects(self::never())->method('persist');$this->expectException(InboxException::class);$p->create($this->payload(),$user);}
 public function testInvalidTelephoneCannotCreateAnAsset():void{[$p,$em,$source,$user]=$this->subject();$em->expects(self::never())->method('persist');$payload=$this->payload();$payload['phone_number']='1199';$this->expectException(InboxException::class);$p->create($payload,$user);}
 public function testResponseLostRetryReturnsExistingNumberWithoutAnotherWrite():void{$existing=(new MetaAsset(25))->setName('New number')->setPhoneNumber('+5511999999999');[$p,$em,$source,$user]=$this->subject(existing:$existing);$em->expects(self::never())->method('persist');self::assertSame(25,$p->create($this->payload(),$user)['id']);}
 public function testDuplicateNumberCannotCreateAnotherSession():void{[$p,$em,$source,$user]=$this->subject(duplicate:new MetaAsset(25));$em->expects(self::never())->method('persist');$this->expectException(InboxException::class);$p->create($this->payload(),$user);}
}
