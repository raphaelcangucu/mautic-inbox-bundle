<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Mobile;

use MauticPlugin\MauticInboxBundle\Application\Mobile\MediaRoutes;
use PHPUnit\Framework\TestCase;

final class MediaRoutesTest extends TestCase
{
    public function testAnEmailAttachmentGoesThroughTheBearerProxyAndTheAppGetsNoHtml(): void
    {
        $items = MediaRoutes::items([[
            'kind' => 'message',
            'email' => ['has_html' => true, 'html' => '<p>Oi</p>', 'kind' => 'message'],
            'attachments' => [
                ['type' => 'document', 'url' => 'https://localhost:8443/s/inbox/api/email/7/attachments/0', 'available' => true],
                ['type' => 'image', 'url' => 'https://localhost:8443/s/inbox/api/email/7/attachments/12', 'available' => true],
                ['type' => 'document', 'url' => null, 'available' => false],
                ['type' => 'document', 'url' => 'https://evil.test/s/inbox/api/email/7/attachments/0', 'available' => true],
            ],
        ]], 'https://localhost:8443');

        self::assertSame('/inbox/mobile/api/email/7/attachments/0', $items[0]['attachments'][0]['url']);
        self::assertSame('/inbox/mobile/api/email/7/attachments/12', $items[0]['attachments'][1]['url']);
        self::assertNull($items[0]['attachments'][2]['url']);
        self::assertSame('https://evil.test/s/inbox/api/email/7/attachments/0', $items[0]['attachments'][3]['url'], 'Outra origem nunca ganha o Bearer.');
        self::assertArrayNotHasKey('html', $items[0]['email']);
        self::assertTrue($items[0]['email']['has_html']);
    }

    public function testTheQrRewriteIsUnchanged(): void
    {
        $items = MediaRoutes::items([['attachments' => [['url' => 'https://mautic.test/s/whatsqr/media/91']]]], 'https://mautic.test');

        self::assertSame('/s/inbox/api/media/91', $items[0]['attachments'][0]['url']);
    }
}
