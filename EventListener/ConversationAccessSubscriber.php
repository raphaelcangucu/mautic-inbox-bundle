<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\EventListener;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use MauticPlugin\MauticInboxBundle\Entity\{ConversationState,ConversationStateRepository,OutboundRequest};
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\{KernelEvents,Event\ControllerEvent};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Protect alternate browser routes; mobile bearer routes check after authentication. */
final class ConversationAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private ConversationAccess $access,private UserHelper $users,private ConversationStateRepository $states,private EntityManagerInterface $em) {}
    public static function getSubscribedEvents(): array { return [KernelEvents::CONTROLLER=>['check',-10]]; }
    public function check(ControllerEvent $event): void
    {
        $request=$event->getRequest();$route=(string)$request->attributes->get('_route','');
        $controller=$event->getController();
        $class=is_array($controller) ? (is_object($controller[0]) ? get_class($controller[0]) : (string)$controller[0]) : '';
        if ('mautic_webchat_session' === $route) {
            $widgetClass='MauticPlugin\\MauticWebChatBundle\\Entity\\ChatWidget';
            if (class_exists($widgetClass)) {
                $widget=$this->em->getRepository($widgetClass)->findOneBy(['publicKey'=>$request->attributes->get('publicKey')]);
                if ($widget && !empty($widget->getAsset()->getSettings()['inbox_review_only'])) {
                    foreach (['name','email','phone'] as $field) if ('hidden'!==$widget->fieldPolicy($field)) throw new NotFoundHttpException();
                    try { $payload=json_decode($request->getContent(),true,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new NotFoundHttpException(); }
                    if (!is_array($payload)) throw new NotFoundHttpException();
                    unset($payload['identity_token']);
                    $request->initialize($request->query->all(),$request->request->all(),$request->attributes->all(),$request->cookies->all(),$request->files->all(),$request->server->all(),json_encode($payload,JSON_THROW_ON_ERROR));
                }
            }
        }
        $user=$this->users->getUser();
        if (!$user instanceof User || !$user->getId()) return;
        // The old connector UI has global queries. Restricted operators use the
        // scoped Inbox instead; do not leave a global read/write alternative open.
        if ('mautic_mcp_http_endpoint' === $route && !$this->access->scope($user)['all']) throw new NotFoundHttpException();
        if (str_contains($class,'MauticMetaBundle\\Controller\\') && !str_ends_with($class,'WebhookController') && !$this->access->scope($user)['all']) throw new NotFoundHttpException();
        if (!str_contains($class,'MauticInboxBundle\\Controller\\') && !str_ends_with($class,'MauticWhatsQrBundle\\Controller\\MediaController')) return;
        if (str_ends_with($class,'MobileApiController')) return;
        $state=null;$requested=false;
        if ($request->attributes->has('stateId')) {$requested=true;$state=$this->states->find((int)$request->attributes->get('stateId'));}
        elseif ($request->attributes->has('messageId')) {$requested=true;$entity=$this->em->find(MetaMessage::class,(int)$request->attributes->get('messageId'));$state=$entity ? $this->states->findOneBy(['conversation'=>$entity->getConversation()]) : null;}
        elseif ($request->attributes->has('outboundId')) {$requested=true;$entity=$this->em->find(OutboundRequest::class,(int)$request->attributes->get('outboundId'));$state=$entity ? $this->states->findOneBy(['conversation'=>$entity->getConversation()]) : null;}
        if ($requested && (!$state || !$this->access->canView($state,$user))) throw new NotFoundHttpException();
    }
}
