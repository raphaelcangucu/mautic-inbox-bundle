<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Controller;
use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidgetRepository;
use Symfony\Component\HttpFoundation\Response;
final class ReviewWebChatController extends CommonController
{
    public function show(string $publicKey,ChatWidgetRepository $widgets): Response
    {
        $widget=$widgets->findOneBy(['publicKey'=>$publicKey,'published'=>true]);
        if (!$widget || empty($widget->getAsset()->getSettings()['inbox_review_only'])) throw $this->createNotFoundException();
        $nonce = base64_encode(random_bytes(24));
        return $this->render('@MauticInbox/Review/webchat.html.twig',['widget'=>$widget, 'nonce'=>$nonce],new Response('',200,['Cache-Control'=>'no-store','Referrer-Policy'=>'same-origin','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'self'; script-src 'nonce-$nonce'; frame-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'"]));
    }
}
