<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticWhatsQrBundle\Application\PairingScreen;
use MauticPlugin\MauticWhatsQrBundle\Domain\PairingView;
use MauticPlugin\MauticWhatsQrBundle\Driver\SessionDriverFactory;
use MauticPlugin\MauticWhatsQrBundle\Infrastructure\QrEncoder;

/** Uses the optional WhatsQR plugin without exposing its service credentials. */
final class QrPairing
{
    public function __construct(private EntityManagerInterface $em,private CorePermissions $permissions,private ?SessionDriverFactory $drivers=null,private ?PairingScreen $screen=null) {}

    private function access(bool $edit=false): void
    {
        if (!$this->permissions->isGranted($edit?'meta:connections:edit':'meta:connections:view')) { throw new InboxException('Seu usuário não pode gerenciar estas conexões.',403); }
        if (!$this->drivers || !$this->screen || !defined(AssetType::class.'::WhatsAppQrSession')) { throw new InboxException('O plugin WhatsQR não está disponível nesta instância.',501); }
    }

    public function connections(): array
    {
        $this->access();$items=[];
        foreach($this->em->getRepository(MetaAsset::class)->findBy(['type'=>constant(AssetType::class.'::WhatsAppQrSession')->value,'isPublished'=>true],['name'=>'ASC']) as $asset){
            $items[]=['id'=>$asset->getId(),'name'=>$asset->getName(),'status'=>(string)($asset->getSettings()['whatsqr_session_status']??'unknown'),'can_pair'=>$this->permissions->isGranted('meta:connections:edit')];
        }
        return ['items'=>$items];
    }

    private function asset(int $id): MetaAsset
    {
        $this->access(true);$asset=$this->em->find(MetaAsset::class,$id);
        if(!$asset instanceof MetaAsset || $asset->getType()!==constant(AssetType::class.'::WhatsAppQrSession') || !$asset->isPublished() || $asset->getStatus()!=='active'){throw new InboxException('Conexão WhatsQR indisponível.',404);}
        return $asset;
    }

    public function status(int $id): array
    {
        $asset=$this->asset($id);
        try{
            $driver=$this->drivers->forAsset($asset);$state=$driver->serviceSessions()[$asset->getExternalId()]??null;
            $view=$state?$this->screen->view($state):new PairingView(PairingView::READY);
            if(PairingView::WAITING===$view->stage){$view=new PairingView(PairingView::WAITING,qr:$driver->pairingQr($asset)??$state->qr);}
            $image=PairingView::WAITING===$view->stage && $view->qr ? base64_encode(QrPng::encode(QrEncoder::matrix($view->qr))) : null;
            return ['id'=>$id,'name'=>$asset->getName(),'stage'=>$view->stage,'cause'=>$view->cause,'can_start'=>PairingView::READY===$view->stage,'can_regenerate'=>$state && $this->screen->canReset($state),'image_base64'=>$image,'image_mime'=>'image/png','version'=>hash('sha256',$view->stage.'|'.($view->qr??'')),'refresh_after'=>5];
        }catch(\Throwable){throw new InboxException('Não foi possível consultar o WhatsQR. Tente atualizar em instantes.',503);}
    }

    public function start(int $id,bool $regenerate): array
    {
        $asset=$this->asset($id);
        try{
            $driver=$this->drivers->forAsset($asset);$state=$driver->serviceSessions()[$asset->getExternalId()]??null;
            if($state){
                // Connected/reconnecting/refused sessions must never be erased by this action.
                if($regenerate){if(!$this->screen->canReset($state)){throw new InboxException('Esta sessão não permite gerar outro código. Atualize o status.',409);}$driver->closeSession($asset);}
                else{return $this->status($id);}
            }
            $driver->openSession($asset);
        }catch(InboxException $e){throw $e;}catch(\Throwable){throw new InboxException('Não foi possível preparar o QR Code. Atualize antes de tentar novamente.',503);}
        return $this->status($id);
    }
}
