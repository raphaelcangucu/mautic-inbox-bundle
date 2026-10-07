<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use MauticPlugin\MauticInboxBundle\Application\NotificationBatch;
use PHPUnit\Framework\TestCase;

final class NotificationBatchTest extends TestCase
{
    public function testMixedBatchOnlyNotifiesNewMessagesAndNeverExposesPayloads(): void
    {
        $result = NotificationBatch::present([
            ['id' => 11, 'state_id' => 1, 'notification_payload' => ['whatsqr' => ['historical' => true], 'private' => 'old text']],
            ['id' => 12, 'state_id' => 2, 'notification_payload' => ['message' => ['text' => 'new text']]],
            ['id' => 13, 'state_id' => 1, 'notification_payload' => ['message' => ['historical' => true]]],
        ], 10);
        self::assertSame([['id' => 12, 'state_id' => 2]], $result['notifications']);
        self::assertSame(13, $result['notification_cursor']);
        self::assertFalse($result['notifications_more']);
    }

    public function testEntireHistoryPageIsSilentButItsCursorAdvances(): void
    {
        $rows = [];
        for ($i = 1; $i <= 101; ++$i) { $rows[] = ['id' => $i, 'state_id' => 7, 'notification_payload' => ['whatsqr' => ['historical' => true]]]; }
        $result = NotificationBatch::present($rows, 0);
        self::assertSame([], $result['notifications']);
        self::assertSame(100, $result['notification_cursor']);
        self::assertTrue($result['notifications_more']);
    }

    public function testLegacyNotificationsAndEmptyBatchKeepTheirBehavior(): void
    {
        self::assertSame(['notifications' => [], 'notification_cursor' => 25, 'notifications_more' => false], NotificationBatch::present([], 25));
        self::assertSame([['id' => 26, 'state_id' => 3]], NotificationBatch::present([['id' => 26, 'state_id' => 3]], 25)['notifications']);
    }
}
