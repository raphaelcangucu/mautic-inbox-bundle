<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

/** Assignment is always explicit; only administrators can take another operator's chat. */
final class AssignmentPolicy
{
    public static function canTake(?int $assigneeId, int $userId, bool $administrator): bool
    {
        return null === $assigneeId || $assigneeId === $userId || $administrator;
    }
}
