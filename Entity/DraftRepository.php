<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Entity;
use Mautic\CoreBundle\Entity\CommonRepository;
/** @extends CommonRepository<Draft> */
final class DraftRepository extends CommonRepository { public function getTableAlias(): string { return 'id'; } }
