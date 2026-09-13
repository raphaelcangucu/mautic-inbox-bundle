<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Entity;
use Mautic\CoreBundle\Entity\CommonRepository;
/** @extends CommonRepository<Note> */
final class NoteRepository extends CommonRepository { public function getTableAlias(): string { return 'in'; } }
