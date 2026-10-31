<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Email;

use MauticPlugin\MauticInboxBundle\Application\Email\CampaignEmailPolicy;
use PHPUnit\Framework\TestCase;

/** As quatro saidas por contato da acao "Iniciar conversa por e-mail", e a idempotencia. */
final class CampaignEmailPolicyTest extends TestCase
{
    public function testAContactWithoutEmailFails(): void
    {
        self::assertSame(CampaignEmailPolicy::NO_EMAIL, CampaignEmailPolicy::outcome(null, false, null, null, 5));
    }

    public function testAContactWithEmailDncFails(): void
    {
        self::assertSame(CampaignEmailPolicy::DNC, CampaignEmailPolicy::outcome('joao@example.com', true, null, null, 5));
    }

    public function testACampaignNeverTalksOverAPerson(): void
    {
        self::assertSame(
            CampaignEmailPolicy::IN_SERVICE,
            CampaignEmailPolicy::outcome('joao@example.com', false, ['lifecycle' => 'open', 'assigned' => true, 'takeover' => true], 5, 5),
        );
        self::assertSame(
            CampaignEmailPolicy::IN_SERVICE,
            CampaignEmailPolicy::outcome('joao@example.com', false, ['lifecycle' => 'snoozed', 'assigned' => true, 'takeover' => true], 5, 5),
            'Adiada continua em atendimento.',
        );
    }

    public function testAConversationThatIsNotInServiceIsContinued(): void
    {
        foreach ([
            'resolvida' => ['lifecycle' => 'resolved', 'assigned' => true, 'takeover' => true],
            'sem atendente' => ['lifecycle' => 'open', 'assigned' => false, 'takeover' => true],
            'sem takeover' => ['lifecycle' => 'open', 'assigned' => true, 'takeover' => false],
        ] as $case => $state) {
            self::assertSame(CampaignEmailPolicy::SEND, CampaignEmailPolicy::outcome('joao@example.com', false, $state, 5, 5), $case);
        }
    }

    public function testANewConversationIsStarted(): void
    {
        self::assertSame(CampaignEmailPolicy::SEND, CampaignEmailPolicy::outcome('joao@example.com', false, null, null, 5));
    }

    public function testAnEmailOwnedByAnotherContactIsNotTakenOver(): void
    {
        self::assertSame(CampaignEmailPolicy::OTHER_CONTACT, CampaignEmailPolicy::outcome('joao@example.com', false, null, 9, 5));
    }

    public function testTheQueueKeyIsTheCampaignLog(): void
    {
        self::assertSame('campaign:4410', CampaignEmailPolicy::idempotencyKey(4410));
    }
}
