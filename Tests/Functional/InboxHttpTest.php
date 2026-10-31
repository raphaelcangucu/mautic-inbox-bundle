<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\CannedResponse;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

final class InboxHttpTest extends MauticMysqlTestCase
{
    public function testNativePageAndListRenderForAuthorizedAgent(): void
    {
        $crawler = $this->client->request('GET', '/s/inbox');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#inbox-app'));
        self::assertNotEmpty($crawler->filter('#inbox-app')->attr('data-csrf'));
        self::assertSame('/s/inbox', $crawler->filter('#inbox-app')->attr('data-index-url'));
        self::assertSame('/s/inbox/conversations/0', $crawler->filter('#inbox-app')->attr('data-conversation-url'));
        self::assertSame('0', $crawler->filter('#inbox-app')->attr('data-initial-state-id'));
        self::assertSame('/s/inbox/api/outbound/0/retry', $crawler->filter('#inbox-app')->attr('data-retry-url'));
        // Settings, the sound toggle and the canned-response form are drawn by Svelte inside the
        // mount point, so the server ships the data they need, not the markup itself.
        self::assertNotEmpty($crawler->filter('#inbox-app')->attr('data-canned-settings'));
        self::assertSame('', (string) $crawler->filter('#inbox-app')->html(), 'The mount point ships empty; anything inside it would be drawn twice.');
        $this->client->request('GET', '/s/inbox/api/conversations?queue=all');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('counts', $data);
    }

    public function testConversationPermalinkRendersTheSameWorkspaceWithAnInitialSelection(): void
    {
        // A permalink to a conversation the operator cannot see, or that is gone, answers 404 on
        // purpose, so the deep link has to point at a conversation that really exists.
        $id = (string) $this->state()->getId();
        $crawler = $this->client->request('GET', '/s/inbox/conversations/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame($id, $crawler->filter('#inbox-app')->attr('data-initial-state-id'));
        self::assertSame('/s/inbox/conversations/0', $crawler->filter('#inbox-app')->attr('data-conversation-url'));

        $this->client->xmlHttpRequest('GET', '/s/inbox/conversations/'.$id);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/s/inbox/conversations/'.$id, $data['route']);
        self::assertStringContainsString('data-initial-state-id="'.$id.'"', $data['newContent']);
    }

    public function testPermalinkToAConversationThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', '/s/inbox/conversations/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNativeAjaxNavigationReturnsMauticEnvelope(): void
    {
        $this->client->xmlHttpRequest('GET', '/s/inbox');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('inbox', $data['mauticContent']);
        self::assertSame('/s/inbox', $data['route']);
        self::assertStringContainsString('id="inbox-app"', $data['newContent']);
    }

    public function testSseEndpointRequiresTheSameInboxPermission(): void
    {
        $this->client->request('GET', '/s/inbox/api/stream?once=1');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/event-stream; charset=UTF-8');
    }

    public function testSseRejectsUserWithoutInboxPermission(): void
    {
        $sales = $this->em->getRepository(User::class)->findOneBy(['username' => 'sales']);
        $this->loginUser($sales);
        $this->client->request('GET', '/s/inbox/api/stream?once=1');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMutationRejectsMissingCsrf(): void
    {
        $state = $this->state();
        $this->client->request('POST', '/s/inbox/api/conversations/'.$state->getId().'/note', [], [], ['CONTENT_TYPE' => 'application/json'], '{"body":"Must not be saved"}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em->getRepository(\MauticPlugin\MauticInboxBundle\Entity\Note::class)->findAll());
    }

    public function testCannedResponsesCanBeCreatedEditedAndSafelyRemovedFromSettings(): void
    {
        $crawler = $this->client->request('GET', '/s/inbox');
        $csrf = $crawler->filter('#inbox-app')->attr('data-csrf');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf];

        $this->client->request('POST', '/s/inbox/api/canned-responses', [], [], $headers, json_encode([
            'name' => 'Saudação do relatório',
            'body' => 'Olá! Seu relatório está pronto.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $item = current(array_filter($created['items'], static fn (array $response): bool => 'Saudação do relatório' === $response['name']));
        self::assertIsArray($item);
        self::assertTrue($item['enabled']);

        $this->client->request('PUT', '/s/inbox/api/canned-responses/'.$item['id'], [], [], $headers, json_encode([
            'name' => 'Relatório disponível',
            'body' => 'Olá! O relatório já está disponível para consulta.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $updated = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Olá! O relatório já está disponível para consulta.', current(array_filter($updated['items'], static fn (array $response): bool => $item['id'] === $response['id']))['body']);

        $this->client->request('DELETE', '/s/inbox/api/canned-responses/'.$item['id'], [], [], $headers, '{}');
        self::assertResponseIsSuccessful();
        $deleted = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], array_values(array_filter($deleted['items'], static fn (array $response): bool => $item['id'] === $response['id'])));

        $this->em->clear();
        $archived = $this->em->getRepository(CannedResponse::class)->find($item['id']);
        self::assertInstanceOf(CannedResponse::class, $archived);
        self::assertFalse($archived->isEnabled(), 'Removing a canned response should archive it instead of deleting team data.');
    }

    public function testUserWithoutInboxPermissionCannotReadMessages(): void
    {
        $state = $this->state();
        $sales = $this->em->getRepository(User::class)->findOneBy(['username' => 'sales']);
        // The switch has to happen before the client makes a request: once a session exists the
        // cookie wins and the page keeps answering as the previous user.
        $this->loginUser($sales);
        $this->client->request('GET', '/s/inbox/api/conversations?queue=all');
        self::assertResponseStatusCodeSame(403);
        // A conversation this operator cannot see reads as absent, not as forbidden, so the
        // answer says nothing about whether it exists.
        $this->client->request('GET', '/s/inbox/conversations/'.$state->getId());
        self::assertResponseStatusCodeSame(404);
    }

    private function state(): ConversationState
    {
        $connection = (new MetaConnection())->setName('Inbox http test')->setStatus('active');
        $asset = (new MetaAsset())->setConnection($connection)->setName('Inbox http test')->setExternalId('inbox-http-test')->setType(AssetType::InstagramAccount)->setStatus('active')->setIsPublished(true);
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('instagram')->setRecipient('inbox-http-test');
        $state = (new ConversationState())->setConversation($conversation);
        foreach ([$connection, $asset, $conversation, $state] as $entity) { $this->em->persist($entity); }
        $this->em->flush();

        return $state;
    }
}
