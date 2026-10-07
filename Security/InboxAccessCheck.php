<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Security;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Push\InboxAccess;
final class InboxAccessCheck implements InboxAccess
{
    public function __construct(private ConversationAccess $access) {}
    public function canViewInbox(User $user): bool { return $this->access->canViewInbox($user); }
}
