<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Entity;

use MauticPlugin\MauticInboxBundle\Entity\CommentContext;
use PHPUnit\Framework\TestCase;

final class CommentContextTest extends TestCase
{
    public function testSourceIdentityIsImmutable(): void
    {
        $context = (new CommentContext())->setAccountId('account-1')->setMediaId('media-1')->setCommentId('comment-1')->setParticipantId('person-1');
        $context->setMediaId('media-1');
        self::assertSame('comment-1', $context->getCommentId());

        $this->expectException(\LogicException::class);
        $context->setMediaId('other-media');
    }
}
