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
use Mautic\UserBundle\Entity\User;

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
            $items[]=['id'=>$asset->getId(),'name'=>$asset->getName(),'phone'=>$asset->getPhoneNumber(),'status'=>(string)($asset->getSettings()['whatsqr_session_status']??'unknown'),'can_pair'=>$this->permissions->isGranted('meta:connections:edit')];
        }
        $profiles=[];
        if ($this->permissions->isGranted('meta:connections:edit') && method_exists($this->drivers,'configureAdditionalAsset')) {
            foreach($this->em->getRepository(MetaAsset::class)->findBy(['type'=>constant(AssetType::class.'::WhatsAppQrSession')->value,'isPublished'=>true,'status'=>'active'],['name'=>'ASC']) as $asset) {
                if (($asset->getSettings()['whatsqr_engine']??'whatsmeow')!=='whatsmeow') {continue;}
                try {$this->drivers->forAsset($asset);$this->drivers->webhookSecret($asset);}catch(\Throwable){continue;}
                $profiles[]=['id'=>$asset->getId(),'name'=>$asset->getName()];
            }
        }
        return ['items'=>$items,'creation'=>['can_create'=>count($profiles)>0,'profiles'=>$profiles]];
    }

    /** Creates a separate session; never resets or reuses another number's session. */
    public function create(array $payload, User $user): array
    {
        $this->access(true);
        $name=$payload['name']??null;$phone=$payload['phone_number']??null;$requestId=$payload['request_id']??null;$profile=$payload['profile_id']??null;
        if (!is_string($name)||!is_string($phone)||!is_string($requestId)||!is_int($profile)||$profile<1) {throw new InboxException('Dados da conexão inválidos.',400);}
        $name=trim($name);$phone=preg_replace('/[\s().-]/','',trim($phone));
        if ($name===''||mb_strlen($name)>80||!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$requestId)) {throw new InboxException('Informe um nome e o telefone com código do país.',400);}
        if (!method_exists($this->drivers,'configureAdditionalAsset')) {throw new InboxException('Atualize o plugin WhatsQR para adicionar números pelo app.',501);}
        $source=$this->asset($profile);
        if (($source->getSettings()['whatsqr_engine']??'whatsmeow')!=='whatsmeow') {throw new InboxException('Selecione um servidor whatsmeow configurado.',422);}
        // Request IDs are scoped to the authenticated user, making a lost response retry safe.
        $externalId='mobile-'.$user->getId().'-'.$requestId;
        $repository=$this->em->getRepository(MetaAsset::class);
        $existing=$repository->findOneBy(['externalId'=>$externalId,'type'=>AssetType::WhatsAppQrSession->value]);
        if ($existing instanceof MetaAsset) {
            if ($existing->getName()!==$name||$existing->getPhoneNumber()!==$phone) {throw new InboxException('Este pedido já foi utilizado.',409);}
            return $this->connection($existing);
        }
        if ($repository->findOneBy(['phoneNumber'=>$phone,'type'=>AssetType::WhatsAppQrSession->value,'isPublished'=>true])) {throw new InboxException('Este número já está cadastrado. Selecione a conexão existente.',409);}
        $asset=(new MetaAsset())->setConnection($source->getConnection())->setType(AssetType::WhatsAppQrSession)->setExternalId($externalId)->setName($name)->setPhoneNumber($phone)->setStatus('active')->setIsPublished(true);
        $asset->setSettings(['default_region'=>'BR','anti_spam_enabled'=>true,'daily_send_limit'=>50,'hourly_send_limit'=>20,'recipient_daily_limit'=>3,'recipient_cooldown_seconds'=>300,'enforce_customer_service_window'=>false,'whatsqr_session_status'=>'unknown','whatsqr_mobile_created_by'=>$user->getId(),'whatsqr_mobile_provisioned'=>true]);
        try {$this->drivers->configureAdditionalAsset($asset,$source,bin2hex(random_bytes(32)));}
        catch(\Throwable){throw new InboxException('Não foi possível usar o servidor whatsmeow configurado.',503);}
        $this->em->persist($asset);$this->em->flush();
        return $this->connection($asset);
    }

    private function connection(MetaAsset $asset): array
    {
        return ['id'=>$asset->getId(),'name'=>$asset->getName(),'status'=>(string)($asset->getSettings()['whatsqr_session_status']??'unknown'),'can_pair'=>true];
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
        }catch(\Throwable){
            // A service outage is not a lost WhatsApp pairing. Never offer a credential reset.
            return ['id'=>$id,'name'=>$asset->getName(),'stage'=>PairingView::NOT_DONE,'cause'=>PairingView::CAUSE_SERVICE_DOWN,'can_start'=>false,'can_regenerate'=>false,'image_base64'=>null,'image_mime'=>'image/png','version'=>hash('sha256','service_down|'.$id),'refresh_after'=>5];
        }
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
            if (($asset->getSettings()['whatsqr_mobile_provisioned']??false)===true) {
                if (!method_exists($driver,'registerSession')) {throw new InboxException('Atualize o servidor WhatsQR para cadastrar este número.',501);}
                $driver->registerSession($asset,$this->drivers->webhookSecret($asset));
            }
            $driver->openSession($asset);
        }catch(InboxException $e){throw $e;}catch(\Throwable){throw new InboxException('Não foi possível preparar o QR Code. Atualize antes de tentar novamente.',503);}
        return $this->status($id);
    }
}
