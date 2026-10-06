<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
final class InboxUpdates
{
    public function __construct(private EntityManagerInterface $entityManager, private ConversationAccess $access, private UserHelper $users) {}
    public function token(): string
    {
        $qb=$this->entityManager->createQueryBuilder()->select('s.id AS id','s.version AS version','s.dateModified AS modified','s.lastInboundMessageId AS inbound','c.lastMessageAt AS lastMessage','c.unreadCount AS unread')
            ->from(ConversationState::class,'s')->join('s.conversation','c')->orderBy('s.id');
        $this->access->apply($qb,$this->users->getUser());
        return hash('sha256',json_encode($qb->getQuery()->getArrayResult(),JSON_THROW_ON_ERROR));
    }
}
