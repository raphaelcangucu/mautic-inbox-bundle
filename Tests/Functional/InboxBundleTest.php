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
        self::assertStringContainsString('Unassigned', $content);
        self::assertStringContainsString('Awaiting reply', $content);
        self::assertStringNotContainsString('access_token', $content);
    }
}
