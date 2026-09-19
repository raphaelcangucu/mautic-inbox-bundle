<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;

final class InboxBundleTest extends MauticMysqlTestCase
{
    public function testSchemaRoutesAndIntegrationAreRegistered(): void
    {
        $schema = $this->em->getConnection()->createSchemaManager();
        foreach (['inbox_conversation_states', 'inbox_notes', 'inbox_drafts', 'inbox_event_log', 'inbox_canned_responses', 'inbox_outbound_requests', 'inbox_comment_contexts'] as $table) {
            self::assertTrue($schema->tablesExist([MAUTIC_TABLE_PREFIX.$table]), $table.' must exist');
        }
        self::assertSame('/s/inbox', self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_index')?->getPath());
        self::assertSame('/s/inbox/conversations/{stateId}', self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_conversation')?->getPath());
        self::assertSame('/s/inbox/api/canned-responses/{responseId}', self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_canned_update')?->getPath());
        self::assertSame(['PUT'], self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_canned_update')?->getMethods());
        self::assertSame(['DELETE'], self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_canned_delete')?->getMethods());
        self::assertSame('/s/inbox/api/outbound/{outboundId}/retry', self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_retry')?->getPath());
        self::assertSame(['POST'], self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_retry')?->getMethods());
        self::assertInstanceOf(MetaInboxIntegration::class, self::getContainer()->get(InboxIntegrationInterface::class));
    }

    public function testMutationWithoutCsrfIsRejectedBeforeResourceLookup(): void
    {
        $this->client->request('POST', '/s/inbox/api/conversations/999999/take', [], [], ['CONTENT_TYPE' => 'application/json'], '{"version":1}');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/s/inbox/api/outbound/999999/retry', [], [], ['CONTENT_TYPE' => 'application/json'], '{"request_id":"retry-without-csrf"}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInboxPageUsesNativeShellAndUserLocale(): void
    {
        $this->client->request('GET', '/s/inbox');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        // Os dois rotulos eram texto do Twig quando este teste foi escrito. Hoje viajam
        // dentro do catalogo em `data-translations`, que e um atributo JSON: o espaco de
        // "Awaiting reply" sai como `&#x20;` e a busca literal no HTML nunca acha. Achar
        // "Unassigned" e nao achar "Awaiting reply" media escapamento de atributo, nao
        // traducao.
        //
        // Decodificar o catalogo pergunta a coisa certa -- os rotulos chegaram ao cliente
        // traduzidos -- e passa a valer para rotulo com espaco, acento ou aspas.
        $crawler = $this->client->getCrawler();
        $catalog = json_decode((string) $crawler->filter('#inbox-app')->attr('data-translations'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('Unassigned', $catalog);
        self::assertContains('Awaiting reply', $catalog);
        self::assertStringNotContainsString('access_token', $content);
    }
}
