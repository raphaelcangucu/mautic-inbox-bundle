<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;

final class InboxHttpTest extends MauticMysqlTestCase
{
    public function testNativePageAndListRenderForAuthorizedAgent(): void
    {
        $crawler = $this->client->request('GET', '/s/atendimento');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#inbox-app'));
        self::assertNotEmpty($crawler->filter('#inbox-app')->attr('data-csrf'));
        $this->client->request('GET', '/s/atendimento/api/conversas?queue=all');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('counts', $data);
    }

    public function testNativeAjaxNavigationReturnsMauticEnvelope(): void
    {
        $this->client->xmlHttpRequest('GET', '/s/atendimento');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('inbox', $data['mauticContent']);
        self::assertSame('/s/atendimento', $data['route']);
        self::assertStringContainsString('id="inbox-app"', $data['newContent']);
    }

    public function testSseEndpointRequiresTheSameInboxPermission(): void
    {
        $this->client->request('GET', '/s/atendimento/api/stream?once=1');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/event-stream; charset=UTF-8');
    }

    public function testSseRejectsUserWithoutInboxPermission(): void
    {
        $sales = $this->em->getRepository(User::class)->findOneBy(['username' => 'sales']);
        $this->loginUser($sales);
        $this->client->request('GET', '/s/atendimento/api/stream?once=1');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMutationRejectsMissingCsrfBeforeLookingUpConversation(): void
    {
        $this->client->request('POST', '/s/atendimento/api/conversas/999999/nota', [], [], ['CONTENT_TYPE' => 'application/json'], '{"body":"Must not be saved"}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserWithoutInboxPermissionCannotReadMessages(): void
    {
        $sales = $this->em->getRepository(User::class)->findOneBy(['username' => 'sales']);
        $this->loginUser($sales);
        $this->client->request('GET', '/s/atendimento/api/conversas?queue=all');
        self::assertResponseStatusCodeSame(403);
    }
}
