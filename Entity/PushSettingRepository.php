<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSettingStore;

/**
 * Duas operacoes e mais nada. O contrato estreito existe para que o cofre VAPID dependa
 * disto e nao de um EntityManager, e assim possa ser provado em memoria.
 *
 * @extends CommonRepository<PushSetting>
 */
final class PushSettingRepository extends CommonRepository implements PushSettingStore
{
    public function getTableAlias(): string { return 'ips'; }

    public function get(string $name): ?string
    {
        $row = $this->findOneBy(['name' => $name]);

        return $row?->getValue();
    }

    public function set(string $name, string $value): void
    {
        $row = $this->findOneBy(['name' => $name]) ?? (new PushSetting())->setName($name);
        $row->setValue($value);
        $this->getEntityManager()->persist($row);
        $this->getEntityManager()->flush();
    }
}
