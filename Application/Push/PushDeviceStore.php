<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;

/**
 * O que a inscricao precisa da tabela de aparelhos, e nada alem.
 *
 * O servico depende disto e nao do repositorio concreto: assim as regras de inscricao se
 * provam com um array, sem banco nenhum.
 */
interface PushDeviceStore
{
    public function findByEndpointHash(string $hash): ?PushDevice;

    public function save(PushDevice $device): void;

    public function remove(PushDevice $device): void;

    /** @return PushDevice[] */
    public function activeForUser(User $user): array;
}
