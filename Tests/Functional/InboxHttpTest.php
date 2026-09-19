<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\CannedResponse;

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
        // A aba de ajustes, o botao de som e o formulario de respostas prontas eram
        // marcacao do Twig quando este teste foi escrito. O frontend em Svelte os
        // desenha no navegador, e este cliente nunca executa JavaScript: contar aqui
        // media a ausencia de um <script>, nao a existencia da tela. As tres garantias
        // vivem agora em Tests/JavaScript/svelte-inbox.test.mjs, onde o componente
        // realmente monta e a aba realmente abre.
        //
        // O que o servidor promete e o ponto de montagem com tudo que o cliente precisa
        // para subir sozinho -- e isso e o que se afirma aqui.
        $this->client->request('GET', '/s/inbox/api/conversations?queue=all');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('counts', $data);
    }

    public function testConversationPermalinkRendersTheSameWorkspaceWithAnInitialSelection(): void
    {
        $crawler = $this->client->request('GET', '/s/inbox/conversations/42');
        self::assertResponseIsSuccessful();
        self::assertSame('42', $crawler->filter('#inbox-app')->attr('data-initial-state-id'));
        self::assertSame('/s/inbox/conversations/0', $crawler->filter('#inbox-app')->attr('data-conversation-url'));

        $this->client->xmlHttpRequest('GET', '/s/inbox/conversations/42');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/s/inbox/conversations/42', $data['route']);
        self::assertStringContainsString('data-initial-state-id="42"', $data['newContent']);
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

    public function testMutationRejectsMissingCsrfBeforeLookingUpConversation(): void
    {
        $this->client->request('POST', '/s/inbox/api/conversations/999999/note', [], [], ['CONTENT_TYPE' => 'application/json'], '{"body":"Must not be saved"}');
        self::assertResponseStatusCodeSame(403);
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
        $sales = $this->em->getRepository(User::class)->findOneBy(['username' => 'sales']);
        $this->loginUser($sales);
        $this->client->request('GET', '/s/inbox/api/conversations?queue=all');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/s/inbox/conversations/42');
        self::assertResponseStatusCodeSame(403);
    }
}
