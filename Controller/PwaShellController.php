<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Application\AssetVersion;
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

        return $this->render('@MauticInbox/App/shell.html.twig', [
            'assetVersion'             => AssetVersion::current(),
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
