<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Email;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Application\Email\EmailReply;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use PHPUnit\Framework\TestCase;

final class EmailReplyTest extends TestCase
{
    public function testAnEmptySubjectAnswersTheLastOne(): void
    {
        self::assertSame('Re: Por que você ainda não depositou?', EmailReply::subject(null, 'Por que você ainda não depositou?'));
        self::assertSame('Re: Por que você ainda não depositou?', EmailReply::subject('   ', 'Por que você ainda não depositou?'));
    }

    public function testRepliesDoNotPileUpPrefixes(): void
    {
        self::assertSame('Re: Proposta', EmailReply::subject('', 'Re: Proposta'));
        self::assertSame('RES: Proposta', EmailReply::subject('', 'RES: Proposta'));
        self::assertSame('AW: Angebot', EmailReply::subject('', 'AW: Angebot'));
    }

    public function testTheAgentCanWriteTheirOwnSubject(): void
    {
        self::assertSame('Novo assunto', EmailReply::subject(' Novo assunto ', 'Re: Proposta'));
    }

    public function testALineBreakNeverReachesTheHeader(): void
    {
        self::assertSame('Oi Bcc: vitima@example.com', EmailReply::subject("Oi\r\nBcc: vitima@example.com", null));
    }

    public function testTheFirstEmailOfAConversationNeedsASubject(): void
    {
        $this->expectException(InboxException::class);
        $this->expectExceptionMessage('mautic.inbox.ui.email_subject_required');
        EmailReply::subject('', null);
    }

    public function testTheSubjectIsBounded(): void
    {
        self::assertSame(EmailReply::MAX_SUBJECT, mb_strlen(EmailReply::subject(str_repeat('á', 400), null)));
    }

    public function testTheAgentSignsWithTheFirstName(): void
    {
        $user = (new User())->setFirstName('Ana')->setLastName('Souza');

        self::assertSame('Ana', EmailReply::authorName($user));
    }

    public function testAnEmailSendHasRetriesBecauseTheMessageIdMakesThemSafe(): void
    {
        self::assertSame(9, ConversationActions::sendAttempts((new MetaAsset())->setType(AssetType::EmailAccount)));
    }

    public function testAnEmailConversationIsNeverSentAsAnInstagramMessage(): void
    {
        $actions = (new \ReflectionClass(ConversationActions::class))->newInstanceWithoutConstructor();
        $target = (new \ReflectionMethod(ConversationActions::class, 'outboundTarget'))->invoke(
            $actions,
            (new MetaConversation())->setChannel('email')->setRecipient('joao@example.com'),
        );

        self::assertSame(['email_message', 'joao@example.com'], $target);
    }
}
