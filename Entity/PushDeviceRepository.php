<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/** @extends CommonRepository<PushDevice> */
final class PushDeviceRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'ipd'; }
}
