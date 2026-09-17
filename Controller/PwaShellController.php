<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use Symfony\Component\HttpFoundation\Response;

/**
 * O atendimento em shell proprio, para instalar no aparelho.
 *
 * Rotas separadas das de /s/inbox de proposito: a tela que a equipe usa no desktop nao muda
 * em nada, e um defeito daqui nao alcanca aquilo. Os componentes Svelte sao os mesmos, montados
 * sem o menu lateral e sem a barra superior do Mautic.
 */
class PwaShellController extends CommonController
{
    public function index(CorePermissions $permissions, UserHelper $users, InboxQuery $query, ?int $stateId = null): Response
    {
        if (!$permissions->isGranted('inbox:conversations:view')) {
            throw $this->createAccessDeniedException();
        }

        $user = $users->getUser();

        // A versao vem da data dos proprios arquivos compilados. Sem isto o shell pedia o bundle
        // com uma etiqueta fixa, e um app ja instalado ficava preso na versao que baixou na
        // primeira visita: dentro dele nao ha barra de endereco nem recarregar forcado, entao o
        // atendente nao teria como sair daquele estado sem reinstalar.
        $dist    = __DIR__.'/../Assets';
        $version = (string) max(
            (int) @filemtime($dist.'/dist/inbox-app.js'),
            (int) @filemtime($dist.'/css/inbox.css')
        );

        return $this->render('@MauticInbox/App/shell.html.twig', [
            'assetVersion'             => $version,
            'currentUserId'            => $user->getId(),
            'initialStateId'           => null !== $stateId && $stateId > 0 ? $stateId : null,
            'users'                    => $query->users(),
            'cannedResponses'          => $query->cannedResponses(),
            'automationRules'          => $query->automationRules(),
            'channelNotices'           => $query->channelNotices(),
            'canManageCannedResponses' => $permissions->isGranted('inbox:templates:edit'),
        ]);
    }
}
