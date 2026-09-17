<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;

/** Inscreve, desinscreve e lista os aparelhos de um usuario. */
final class PushSubscriptions
{
    public function __construct(
        private PushDeviceStore $devices,
    ) {
    }

    /**
     * O endpoint e unico no mundo: o mesmo navegador que se inscreve de novo atualiza o
     * registro que ja existe, em vez de criar um segundo. Reinscrever tambem revive um
     * aparelho aposentado — quem voltou a pedir notificacao esta dizendo que voltou.
     */
    public function subscribe(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent): PushDevice
    {
        $device = $this->devices->findByEndpointHash(PushDevice::hashOf($endpoint));
        if (null === $device) {
            $device = (new PushDevice())->setEndpoint($endpoint);
        }

        // O dono passa a ser quem acabou de se inscrever: um mesmo navegador pode trocar de
        // sessao, e o endpoint segue o navegador, nao a conta.
        $device->setUser($user)->setKeys($p256dh, $auth)->setUserAgent($userAgent)->reactivate();
        $this->devices->save($device);

        return $device;
    }

    /** Apaga so o que e do proprio usuario; devolve se algo saiu. */
    public function unsubscribe(User $user, string $endpoint): bool
    {
        $device = $this->devices->findByEndpointHash(PushDevice::hashOf($endpoint));
        if (null === $device || !$this->belongsTo($device, $user)) {
            return false;
        }

        $this->devices->remove($device);

        return true;
    }

    /** @return PushDevice[] */
    public function activeFor(User $user): array
    {
        return $this->devices->activeForUser($user);
    }

    private function belongsTo(PushDevice $device, User $user): bool
    {
        $owner = $device->getUser();
        if ($owner === $user) {
            return true;
        }

        // Fora do Doctrine dois usuarios recem-criados tem id nulo; comparar nulos igualaria
        // qualquer um com qualquer um.
        $ownerId = $owner?->getId();

        return null !== $ownerId && $ownerId === $user->getId();
    }
}
