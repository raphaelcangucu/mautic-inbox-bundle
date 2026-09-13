<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Entity;
use Mautic\CoreBundle\Entity\CommonRepository;
/** @extends CommonRepository<CommentContext> */
final class CommentContextRepository extends CommonRepository { public function getTableAlias(): string { return 'icc'; } }
