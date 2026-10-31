<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Mobile;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticInboxBundle\Application\Mobile\ContactPhotoCache;
use PHPUnit\Framework\TestCase;

final class ContactPhotoCacheTest extends TestCase
{
    public function testOnlyMetaCdnAddressesOverHttpsAreFetched(): void
    {
        self::assertTrue(ContactPhotoCache::acceptable('https://platform-lookaside.fbsbx.com/platform/profilepic/?eai=abc'));
        self::assertTrue(ContactPhotoCache::acceptable('https://scontent.cdninstagram.com/v/t51/photo.jpg'));
        self::assertTrue(ContactPhotoCache::acceptable('https://x.fbcdn.net/photo.jpg'));

        self::assertFalse(ContactPhotoCache::acceptable('http://scontent.cdninstagram.com/photo.jpg'), 'sem https');
        self::assertFalse(ContactPhotoCache::acceptable('https://evil.com/photo.jpg'), 'host de fora');
        self::assertFalse(ContactPhotoCache::acceptable('https://notfbcdn.net.evil.com/x.jpg'), 'host que apenas parece');
        self::assertFalse(ContactPhotoCache::acceptable(null));
        self::assertFalse(ContactPhotoCache::acceptable(''));
    }

    public function testTheFileIsNamedAfterTheIdentitySoASignedUrlChangingDoesNotOrphanIt(): void
    {
        $signed = ContactPhotoCache::name('instagram:7:person-1');
        self::assertSame($signed, ContactPhotoCache::name('instagram:7:person-1'));
        self::assertNotSame($signed, ContactPhotoCache::name('instagram:7:person-2'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.webp$/D', $signed);
    }

    public function testAnUncacheableAddressIsSimplyNotStored(): void
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('https://example.test');
        $cache = new ContactPhotoCache($parameters, sys_get_temp_dir().'/inbox-photo-test');

        self::assertNull($cache->store('instagram:7:person-1', 'https://evil.com/photo.jpg'));
        self::assertNull($cache->store('instagram:7:person-1', null));
        self::assertNull($cache->url('instagram:7:person-1'));
    }
}
