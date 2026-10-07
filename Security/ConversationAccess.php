<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Security;

use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\{PermissionRepository,User};
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;

/** One policy for browser, native bearer, MCP and push workers. */
final class ConversationAccess
{
    public function __construct(private CorePermissions $permissions, private PermissionRepository $roles, private UserHelper $users) {}

    public function hydrate(User $user): void
    {
        // ORM-loaded users do not carry active permissions (unlike browser logins).
        // Refresh from the role rather than a token snapshot; role changes apply now.
        $user->setActivePermissions($user->getRole() ? $this->roles->getPermissionsByRole($user->getRole()) : []);
    }

    public function granted(User $user, string $permission): bool
    {
        if (!$user->isPublished() || !$user->getId()) return false;
        if ($user->isAdmin()) return true;
        [$bundle,$name,$level] = explode(':',$permission);
        // Resolve registered objects before using the short name. Public inbound
        // requests have no browser login to initialize legacy plugin permissions.
        $objects = $this->permissions->getPermissionObjects();
        return isset($objects[$bundle]) && $objects[$bundle]->isGranted($user->getActivePermissions()[$bundle] ?? [],$name,$level);
    }

    public function canViewInbox(User $user): bool
    {
        $this->hydrate($user);
        return $this->granted($user,'inbox:conversations:view') && $this->granted($user,'meta:messages:view');
    }

    public function scope(User $user): array
    {
        if (!$this->canViewInbox($user)) return ['own'=>false,'waiting'=>false,'all'=>false];
        if ($user->isAdmin()) return ['own'=>true,'waiting'=>true,'all'=>true];
        $scope=$user->getActivePermissions()['inbox']['scope'] ?? null;
        // Existing operators gain a safe own + waiting default. An explicit role
        // overrides it, including own-only reviewers and zero-access roles.
        if (null === $scope) return ['own'=>true,'waiting'=>true,'all'=>false];
        return ['own'=>$this->granted($user,'inbox:scope:own'),'waiting'=>$this->granted($user,'inbox:scope:waiting'),'all'=>$this->granted($user,'inbox:scope:all')];
    }

    public static function allows(array $scope, int $actor, ?int $assignee, bool $needsResponse, string $lifecycle): bool
    {
        return $actor > 0 && (!empty($scope['all']) || (!empty($scope['own']) && $assignee === $actor) || (!empty($scope['waiting']) && null === $assignee && $needsResponse && 'open' === $lifecycle));
    }

    public function canView(ConversationState $state, User $user): bool
    {
        return self::allows($this->scope($user),(int)$user->getId(),$state->getAssignee()?->getId(),$state->needsResponse(),$state->getLifecycle());
    }

    public function assertView(ConversationState $state, ?User $user = null): void
    {
        $user ??= $this->users->getUser();
        if (!$user instanceof User || !$this->canView($state,$user)) throw new InboxException('mautic.inbox.ui.conversation_not_found_61bc81',404);
    }

    public function apply(QueryBuilder $qb, User $user, string $alias='s'): void
    {
        $scope=$this->scope($user);
        if ($scope['all']) return;
        $clauses=[];
        if ($scope['own']) {$clauses[]="$alias.assignee = :inboxScopeUser";$qb->setParameter('inboxScopeUser',$user);}
        if ($scope['waiting']) $clauses[]="($alias.assignee IS NULL AND $alias.needsResponse = true AND $alias.lifecycle = 'open')";
        $qb->andWhere($clauses ? '('.implode(' OR ',$clauses).')' : '1 = 0');
    }
}
