<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Declara o app instalavel em TODA pagina do Mautic, inclusive na de login.
 *
 * O navegador so oferece instalacao quando enxerga o manifest na pagina aberta. Deixa-lo
 * apenas no shell, atras da sessao, criava uma ordem impossivel: para instalar era preciso
 * logar, e para logar confortavelmente no celular era preciso ter instalado. Declarando aqui,
 * o atendente instala o app, abre pelo icone e faz o login ja dentro dele — que e a ordem em
 * que as pessoas realmente configuram um aparelho.
 */
final class PwaAssetsSubscriber implements EventSubscriberInterface
{
    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CoreEvents::VIEW_INJECT_CUSTOM_ASSETS => ['declare', 0]];
    }

    public function declare(CustomAssetsEvent $event): void
    {
        $manifest = $this->router->generate('mautic_inbox_manifest');
        $icone    = $this->router->generate('mautic_inbox_icon', ['name' => 'apple-180']);

        $event->addCustomDeclaration('<link rel="manifest" href="'.$manifest.'">');
        // O iOS ignora o manifest para o icone e para o titulo; estas tres linhas sao so dele.
        $event->addCustomDeclaration('<link rel="apple-touch-icon" href="'.$icone.'">');
        $event->addCustomDeclaration('<meta name="apple-mobile-web-app-capable" content="yes">');
        $event->addCustomDeclaration('<meta name="apple-mobile-web-app-title" content="Macro Zap">');
        $event->addCustomDeclaration('<meta name="theme-color" content="#4e5e9e">');
    }
}
