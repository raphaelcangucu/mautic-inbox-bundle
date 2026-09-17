<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;

/**
 * Quem recebe a notificacao de uma mensagem que chegou.
 *
 * A regra inteira mora aqui, isolada de proposito: trocar para preferencias por usuario,
 * horario de silencio ou filtro por canal mexe so neste arquivo.
 *
 *   conversa com dono  -> so o dono
 *   conversa na fila   -> todos os atendentes que podem ver o atendimento
 *   conversa de outro  -> ninguem
 */
final class PushAudience
{
    public function __construct(
        private PushDeviceStore $devices,
        private InboxAccess $access,
    ) {
    }

    /**
     * @return list<PushDevice>
     */
    public function devicesFor(?User $assignee): array
    {
        // Parte dos aparelhos ativos, nao de todos os usuarios: quem nunca se inscreveu nao
        // entra na conta, e a lista fica do tamanho da equipe que realmente usa isto.
        $active = $this->devices->allActive();

        if (null !== $assignee) {
            return array_values(array_filter(
                $active,
                static fn (PushDevice $device): bool => $device->getUser()?->getId() === $assignee->getId(),
            ));
        }

        return array_values(array_filter(
            $active,
            fn (PushDevice $device): bool => null !== $device->getUser() && $this->access->canViewInbox($device->getUser()),
        ));
    }
}
