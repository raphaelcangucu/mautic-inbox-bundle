<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

use Mautic\UserBundle\Entity\User;

/**
 * Contrato estreito para "esta pessoa pode ver o atendimento?".
 *
 * Existe para que a regra de audiencia seja testavel sem contêiner e sem banco: a
 * implementacao real pergunta ao CorePermissions, e o teste responde o que quiser.
 */
interface InboxAccess
{
    public function canViewInbox(User $user): bool;
}
