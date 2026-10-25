<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Mobile;

use MauticPlugin\MauticInboxBundle\Application\Mobile\EmailStartOptions;
use PHPUnit\Framework\TestCase;

final class EmailStartOptionsTest extends TestCase
{
    private const ACCOUNT = ['id' => 31, 'name' => 'Macro Markets', 'address' => 'Contato@MacroMarkets.com', 'active' => true];

    public function testAnAvailableAccountFollowsTheContractShapeExactly(): void
    {
        self::assertSame([[
            'key' => 'email:31',
            'state_id' => null,
            'asset_id' => 31,
            'channel' => 'email',
            'name' => 'Macro Markets · contato@macromarkets.com',
            'address' => 'contato@macromarkets.com',
            'available' => true,
            'reason' => null,
        ]], EmailStartOptions::build([self::ACCOUNT], 'joao@example.com', false, true));
    }

    public function testAContactWithoutAValidEmailSaysSo(): void
    {
        foreach ([null, '', 'joao', 'joao@'] as $email) {
            $option = EmailStartOptions::build([self::ACCOUNT], $email, false, true)[0];
            self::assertFalse($option['available']);
            self::assertSame('Cadastre um e-mail válido no contato.', $option['reason']);
        }
    }

    public function testAContactWithEmailDncSaysSo(): void
    {
        $option = EmailStartOptions::build([self::ACCOUNT], 'joao@example.com', true, true)[0];

        self::assertFalse($option['available']);
        self::assertSame('O contato pediu para não receber e-mails.', $option['reason']);
    }

    public function testAnInactiveAccountSaysSo(): void
    {
        $option = EmailStartOptions::build([['active' => false] + self::ACCOUNT], 'joao@example.com', false, true)[0];

        self::assertFalse($option['available']);
        self::assertSame('Esta conta de e-mail está desativada.', $option['reason']);
    }

    public function testWithoutPermissionNothingIsAvailableButNothingIsHidden(): void
    {
        $option = EmailStartOptions::build([self::ACCOUNT], 'joao@example.com', false, false)[0];

        self::assertFalse($option['available']);
        self::assertNull($option['reason']);
    }

    public function testAnAccountThatAlreadyHasAConversationIsNotOfferedTwice(): void
    {
        self::assertSame([], EmailStartOptions::build([self::ACCOUNT], 'joao@example.com', false, true, [31]));
    }

    public function testTheRecipientIsTheLowercasedEmail(): void
    {
        self::assertSame('joao.lead@example.com', EmailStartOptions::recipient('  Joao.Lead@Example.COM '));
        self::assertNull(EmailStartOptions::recipient('não é e-mail'));
        self::assertNull(EmailStartOptions::recipient(null));
    }
}
