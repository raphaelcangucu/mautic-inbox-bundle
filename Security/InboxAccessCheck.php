<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Security;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Push\InboxAccess;

final class InboxAccessCheck implements InboxAccess
{
    public function __construct(private CorePermissions $permissions)
    {
    }

    public function canViewInbox(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Mesma forma que o InboxController ja usa para checar permissao de OUTRO usuario:
        // isGranted com o mapa de permissoes ativas daquele usuario, nao do usuario da sessao.
        return $this->permissions->isGranted($user->getActivePermissions()['inbox'] ?? [], 'conversations', 'view');
    }
}
