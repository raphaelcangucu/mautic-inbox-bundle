<?php
declare(strict_types=1);
namespace Doctrine\ORM {interface EntityManagerInterface {}}
namespace Symfony\Contracts\Translation {interface TranslatorInterface {public function trans(string $id): string;}}
namespace Symfony\Component\Routing\Generator {interface UrlGeneratorInterface {public const ABSOLUTE_URL=0;}}
namespace MauticPlugin\MauticMetaBundle\Entity {
 class MetaMessage {
  public function __construct(public array $payload, public string $type='unsupported') {}
  public function getPayload(): array {return $this->payload;} public function getMessageType(): string {return $this->type;} public function getError(): ?string {return null;}
 }
}
namespace {
require __DIR__.'/../../Application/MessagePresentation.php';
use Doctrine\ORM\EntityManagerInterface;use Symfony\Contracts\Translation\TranslatorInterface;use Symfony\Component\Routing\Generator\UrlGeneratorInterface;use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;use MauticPlugin\MauticInboxBundle\Application\MessagePresentation;
function check(bool $ok): void {if(!$ok) throw new RuntimeException('QR presentation regression');}
foreach(['en_US','pt_BR'] as $locale) {
 $strings=parse_ini_file(__DIR__.'/../../Translations/'.$locale.'/messages.ini');check(is_array($strings));
 $translator=new class($strings) implements TranslatorInterface {public function __construct(private array $strings) {} public function trans(string $id):string {return $this->strings[$id]??$id;}};
 $presenter=new MessagePresentation(new class implements EntityManagerInterface {},$translator,new class implements UrlGeneratorInterface {});
 $item=$presenter->present(new MetaMessage(['message'=>['text'=>''],'whatsqr'=>[]]));
 check($item['body']===$strings['mautic.inbox.qr.content.unavailable'] && $item['attachments']===[]);
 $item=$presenter->present(new MetaMessage(['message'=>['text'=>''],'whatsqr'=>['unsupported_reason'=>'view_once']]));check($item['body']===$strings['mautic.inbox.qr.content.view_once']);
 $official=$presenter->present(new MetaMessage(['message'=>['errors'=>[['code'=>131051]]]]));check(str_contains($official['body'],'131051') && $official['content_label']===$strings['mautic.inbox.ui.message_not_provided_by_whatsapp_7dbeab']);
 foreach(['contact','location','poll','interactive'] as $type) {
  $item=$presenter->present(new MetaMessage(['message'=>['text'=>'Original summary'],'whatsqr'=>[]],$type));check($item['body']==='Original summary' && $item['content_label']===$strings['mautic.inbox.qr.content.'.$type]);
 }
}
echo "QR presentation: both locales and official channel parity passed; no database or kernel.\n";
}
