<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application;

use MauticPlugin\MauticInboxBundle\Application\ParticipantAvatarRegistry;
use MauticPlugin\MauticInboxBundle\Contract\ParticipantAvatarProviderInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use PHPUnit\Framework\TestCase;

final class ParticipantAvatarRegistryTest extends TestCase
{
    public function testProviderMayReturnOnlyALocalProtectedURL(): void
    {
        $unsafe = $this->createMock(ParticipantAvatarProviderInterface::class);
        $unsafe->method('avatarUrl')->willReturn('//remote.example/photo');
        $safe = $this->createMock(ParticipantAvatarProviderInterface::class);
        $safe->method('avatarUrl')->willReturn('/s/whatsqr/avatars/12');
        self::assertSame('/s/whatsqr/avatars/12', (new ParticipantAvatarRegistry(new \ArrayIterator([$unsafe, $safe])))->url(new MetaConversation()));
        self::assertNull((new ParticipantAvatarRegistry(new \ArrayIterator([])))->url(new MetaConversation()));
    }
}
