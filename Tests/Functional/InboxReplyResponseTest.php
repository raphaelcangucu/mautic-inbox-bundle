<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class InboxReplyResponseTest extends MauticMysqlTestCase
{
    /** @var array<string,string> */
    private array $headers = [];
    private int $stateId = 0;

    public function testReplyReturnsTheCreatedTimelineItem(): void
    {
        $this->conversationAssignedToTheAgent();
        $sent = $this->reply('Item de historico do envio', 'created_item_reply_0001');

        self::assertArrayHasKey('item', $sent);
        self::assertSame('outbound', $sent['item']['kind']);
        self::assertSame('created_item_reply_0001', $sent['item']['request_id']);
        self::assertSame('Item de historico do envio', $sent['item']['body']);

        $this->client->request('GET', '/s/inbox/api/conversations/'.$this->stateId.'/history');
        self::assertResponseIsSuccessful();
        $fromHistory = current(array_filter($this->json()['items'], static fn (array $item): bool => 'outbound' === $item['kind']));
        self::assertIsArray($fromHistory, 'O envio tem que estar no historico.');

        // Comparar com o proprio historico e o que prende as duas formas uma na outra: um array
        // montado a mao no controller passaria nos campos de hoje e divergiria na primeira mudanca
        // do InboxQuery, e o cliente veria a mesma mensagem de dois jeitos.
        $toTheSecond = static function (array $item): array {
            // A coluna de data nao guarda microssegundos: o item lido do banco volta com .000000
            // e o recem-criado com o instante que estava em memoria.
            $item['timestamp'] = substr((string) $item['timestamp'], 0, 19);

            return $item;
        };
        self::assertSame($toTheSecond($fromHistory), $toTheSecond($sent['item']));
    }

    public function testReplyReturnsTheUpdatedConversationSummary(): void
    {
        $this->conversationAssignedToTheAgent();
        $sent = $this->reply('Resumo atualizado pelo envio', 'updated_summary_reply_01');

        self::assertArrayHasKey('summary', $sent);
        self::assertSame($this->stateId, $sent['summary']['id']);
        // O resumo e o de depois do envio, nao o de antes: responder tira a conversa da fila de
        // quem espera resposta, e e esse estado que a lista precisa mostrar sem ir buscar de novo.
        self::assertFalse($sent['summary']['needs_response']);
        self::assertTrue($sent['summary']['human_takeover']);

        $this->client->request('GET', '/s/inbox/api/conversations?queue=all');
        self::assertResponseIsSuccessful();
        $fromList = current(array_filter($this->json()['items'], fn (array $item): bool => $this->stateId === $item['id']));
        self::assertIsArray($fromList, 'A conversa respondida tem que estar na listagem.');
        self::assertSame($fromList, $sent['summary']);
    }

    public function testReplyStillReturnsTheFieldsItAlwaysReturned(): void
    {
        $this->conversationAssignedToTheAgent();
        $sent = $this->reply('Envio que nao pode quebrar quem le os campos antigos', 'legacy_fields_reply_001');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('legacy_fields_reply_001', $sent['request_id']);
        self::assertSame('pending', $sent['status']);
    }

    private function conversationAssignedToTheAgent(): void
    {
        $this->client->disableReboot();
        $connection = (new MetaConnection())->setName('Offline test')->setAppId('offline-inbox-app')->setStatus('active');
        $asset = (new MetaAsset())->setConnection($connection)->setName('Offline Instagram')->setExternalId('offline-asset')->setType(AssetType::InstagramAccount)->setStatus('active');
        $asset->setIsPublished(true);
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('instagram')->setRecipient('offline-user');
        $message = (new MetaMessage())->setAsset($asset)->setConversation($conversation)->setChannel('instagram')->setDirection('inbound')
            ->setMessageType('direct_message')->setRecipient('offline-user')->setExternalId('reply-response-inbound')
            ->setPayload(['text' => 'Preciso de ajuda'])->setStatus('received');
        foreach ([$connection, $asset, $conversation, $message] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        static::getContainer()->get(MetaInboxIntegration::class)->messagePersisted($message);

        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        self::assertInstanceOf(ConversationState::class, $state);
        $this->stateId = (int) $state->getId();

        $crawler = $this->client->request('GET', '/s/inbox');
        self::assertResponseIsSuccessful();
        $this->headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $crawler->filter('#inbox-app')->attr('data-csrf')];

        $this->client->request('POST', '/s/inbox/api/conversations/'.$this->stateId.'/take', [], [], $this->headers, json_encode(['version' => $state->getVersion()], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['can_reply']);
    }

    /** @return array<string,mixed> */
    private function reply(string $body, string $requestId): array
    {
        $this->client->request('POST', '/s/inbox/api/conversations/'.$this->stateId.'/reply', [], [], $this->headers, json_encode(['body' => $body, 'request_id' => $requestId], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string,mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
