<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConnection, MetaConversation, MetaMessage};

final class NotificationsTest extends MauticMysqlTestCase
{
    public function testBaselineExcludesHistoryAndCursorOnlyReturnsNewInboundMessages(): void
    {
        $connection = (new MetaConnection())->setName('Notification fixture');
        $asset = (new MetaAsset())->setConnection($connection)->setExternalId('notifications');
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('instagram')->setRecipient('test');
        $state = (new ConversationState())->setConversation($conversation);
        foreach ([$connection, $asset, $conversation, $state] as $entity) { $this->em->persist($entity); }
        $message = fn (string $direction) => (new MetaMessage())->setAsset($asset)->setConversation($conversation)->setChannel('instagram')->setDirection($direction)->setMessageType('direct_message')->setDateAdded(new \DateTimeImmutable('-2 days'));
        $old = $message('inbound');$this->em->persist($old);$this->em->flush();
        $query = static::getContainer()->get(InboxQuery::class);
        $baseline = $query->notifications(null);
        self::assertSame([], $baseline['notifications']);
        self::assertSame($old->getId(), $baseline['notification_cursor']);
        $this->em->persist($message('outbound'));
        $new = $message('inbound');$this->em->persist($new);$this->em->flush();
        $update = $query->notifications($baseline['notification_cursor']);
        self::assertCount(1, $update['notifications']);
        self::assertEquals($new->getId(), $update['notifications'][0]['id']);
        self::assertEquals($state->getId(), $update['notifications'][0]['state_id']);
        self::assertSame([], $query->notifications($update['notification_cursor'])['notifications']);
        // Replay and contact/status changes do not create another notification.
        $new->setPayload(['text'=>'enriched']);$this->em->persist($new);$this->em->flush();
        self::assertSame([], $query->notifications($update['notification_cursor'])['notifications']);
    }
}
