<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use MauticPlugin\MauticInboxBundle\Application\Push\PushPayload;
use PHPUnit\Framework\TestCase;

final class PushPayloadTest extends TestCase
{
    public function testItCarriesTheContactTheTextAndWhereToGo(): void
    {
        $payload = json_decode(PushPayload::forInboundMessage('Ana Paula', 'Bom dia, tudo bem?', 481, '/s/inbox/conversations/481'), true);

        self::assertSame('Ana Paula', $payload['title']);
        self::assertSame('Bom dia, tudo bem?', $payload['body']);
        self::assertSame(481, $payload['conversationId']);
        self::assertSame('/s/inbox/conversations/481', $payload['url']);
    }

    public function testAContactWithoutANameStillGetsAUsableTitle(): void
    {
        $payload = json_decode(PushPayload::forInboundMessage('   ', 'oi', 1, '/s/inbox'), true);

        self::assertNotSame('', trim($payload['title']));
    }

    public function testLineBreaksCollapseSoTheNotificationStaysOneLine(): void
    {
        $payload = json_decode(PushPayload::forInboundMessage('Ana', "linha um\n\n  linha dois", 1, '/s/inbox'), true);

        self::assertSame('linha um linha dois', $payload['body']);
    }

    public function testALongMessageFitsTheRecordAndKeepsItsDestination(): void
    {
        $payload = PushPayload::forInboundMessage('Ana Paula', str_repeat('mensagem enorme ', 500), 481, '/s/inbox/conversations/481');

        self::assertLessThanOrEqual(PushPayload::MAX_PLAINTEXT, strlen($payload), 'acima disso o servico de push devolve 413');

        $decoded = json_decode($payload, true);
        self::assertSame('Ana Paula', $decoded['title'], 'o titulo nunca e sacrificado');
        self::assertSame('/s/inbox/conversations/481', $decoded['url'], 'notificacao sem destino e pior que notificacao curta');
    }

    public function testTruncationNeverSplitsAnEmojiOrAnAccent(): void
    {
        // Um corte cego em octetos parte caractere multibyte no meio e produz JSON invalido,
        // que o service worker recebe como carga corrompida.
        $payload = PushPayload::forInboundMessage('Ana', str_repeat('coração 🇧🇷 ', 600), 1, '/s/inbox');

        self::assertLessThanOrEqual(PushPayload::MAX_PLAINTEXT, strlen($payload));

        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded, 'o payload truncado precisa continuar sendo JSON valido');
        self::assertSame($decoded['body'], mb_convert_encoding($decoded['body'], 'UTF-8', 'UTF-8'), 'nenhum caractere partido ao meio');
    }

    public function testAMessageExactlyAtTheCeilingIsNotTruncated(): void
    {
        $base = strlen(PushPayload::forInboundMessage('Ana', '', 1, '/s/inbox'));
        $body = str_repeat('a', PushPayload::MAX_PLAINTEXT - $base);

        $payload = PushPayload::forInboundMessage('Ana', $body, 1, '/s/inbox');

        self::assertSame(PushPayload::MAX_PLAINTEXT, strlen($payload));
        self::assertSame($body, json_decode($payload, true)['body']);
    }
}
