<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Entity;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\UserBundle\Entity\User;

/** @extends CommonRepository<OperatorPresence> */
final class OperatorPresenceRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'iop'; }

    /** Sem linha o atendente esta disponivel: e o estado que o Inbox sempre teve. */
    public function availableFor(User $user): bool
    {
        return $this->findOneBy(['user' => $user])?->isAvailable() ?? true;
    }

    public function setFor(User $user, bool $available): OperatorPresence
    {
        $presence = $this->findOneBy(['user' => $user]) ?? (new OperatorPresence())->setUser($user);
        $presence->setAvailable($available);
        $this->getEntityManager()->persist($presence);
        $this->getEntityManager()->flush();

        return $presence;
    }
}
