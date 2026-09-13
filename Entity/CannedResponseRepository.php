<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Entity;
use Mautic\CoreBundle\Entity\CommonRepository;
/** @extends CommonRepository<CannedResponse> */
final class CannedResponseRepository extends CommonRepository { public function getTableAlias(): string { return 'icr'; } }
