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
        self::assertSame('/s/atendimento', self::getContainer()->get('router')->getRouteCollection()->get('mautic_inbox_index')?->getPath());
        self::assertInstanceOf(MetaInboxIntegration::class, self::getContainer()->get(InboxIntegrationInterface::class));
    }

    public function testMutationWithoutCsrfIsRejectedBeforeResourceLookup(): void
    {
        $this->client->request('POST', '/s/atendimento/api/conversas/999999/assumir', [], [], ['CONTENT_TYPE' => 'application/json'], '{"version":1}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInboxPageUsesNativeShellAndPortugueseLabels(): void
    {
        $this->client->request('GET', '/s/atendimento');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Não atribuídas', $content);
        self::assertStringContainsString('Aguardando resposta', $content);
        self::assertStringNotContainsString('access_token', $content);
    }
}
