<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Entity;
use Mautic\CoreBundle\Entity\CommonRepository;
/** @extends CommonRepository<EventLog> */
final class EventLogRepository extends CommonRepository { public function getTableAlias(): string { return 'iel'; } }
