<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Push\PushDeviceStore;

/** @extends CommonRepository<PushDevice> */
final class PushDeviceRepository extends CommonRepository implements PushDeviceStore
{
    public function getTableAlias(): string { return 'ipd'; }

    public function findByEndpointHash(string $hash): ?PushDevice
    {
        return $this->findOneBy(['endpointHash' => $hash]);
    }

    public function save(PushDevice $device): void
    {
        $this->getEntityManager()->persist($device);
        $this->getEntityManager()->flush();
    }

    public function remove(PushDevice $device): void
    {
        $this->getEntityManager()->remove($device);
        $this->getEntityManager()->flush();
    }

    /** @return PushDevice[] */
    public function activeForUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'active' => true], ['id' => 'ASC']);
    }

    public function allActive(): array
    {
        return $this->findBy(['active' => true], ['id' => 'ASC']);
    }
}
