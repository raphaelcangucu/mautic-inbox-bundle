<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use DateTimeImmutable;
use DomainException;
use ReflectionProperty;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\Draft;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

final class InboxPersistenceTest extends MauticMysqlTestCase
{
    public function testTemplateAndUnsupportedMessagesHaveMeaningfulContent(): void
    {
        $conversation = $this->conversation();
        $template = (new \MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate())->setBusinessAccount($conversation->getAsset())->setName('welcome')->setLanguage('pt_BR')->setComponents([['type' => 'BODY', 'text' => 'Olá {{1}}, seu relatório está pronto.']]);
        $this->em->persist($template); $this->em->flush();
        $message = (new MetaMessage())->setAsset($conversation->getAsset())->setChannel('whatsapp')->setDirection('outbound')->setMessageType('template')->setPayload(['template' => ['name' => 'welcome', 'language' => ['code' => 'pt_BR'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Raphael']]]]]]);
        $presenter = static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Application\MessagePresentation::class);
        self::assertSame('Olá Raphael, seu relatório está pronto.', $presenter->present($message)['body']);
        $message->setDirection('inbound')->setMessageType('unsupported')->setPayload(['message' => ['type' => 'unsupported', 'errors' => [['code' => 131051]]]]);
        self::assertStringContainsString('131051', $presenter->present($message)['body']);
        self::assertStringContainsString('não disponibilizou', $presenter->present($message)['body']);
    }

    public function testAcceptedPrivateReplyBlocksDuplicateAndKeepsCommentAuthor(): void
    {
        $conversation = $this->conversation()->setRecipient('comment:source-comment');
        $this->em->persist($conversation);
        $message = $this->inbound($conversation, 'source-comment');
        $message->setMessageType('comment')->setPayload(['commentId' => 'source-comment', 'commenterId' => 'real-person', 'commenterName' => 'ana.teste', 'text' => 'relatorio']);
        $sent = (new MetaMessage())->setAsset($conversation->getAsset())->setConversation($conversation)->setChannel('instagram')->setDirection('outbound')->setRecipient('source-comment')->setMessageType('private_reply')->setExternalId('sent-private')->setStatus('accepted');
        $this->em->persist($message); $this->em->persist($sent); $this->em->flush();
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $query = static::getContainer()->get(InboxQuery::class);
        $detail = $query->detail($state, $admin);
        self::assertSame('ana.teste', $detail['contact_name']);
        self::assertFalse($detail['can_reply']); self::assertFalse($detail['can_take_and_reply']);
        self::assertStringContainsString('já recebeu', $detail['reply_blocked_reason']);
        $actions = static::getContainer()->get(ConversationActions::class);
        $state = $actions->take($state, $admin, $state->getVersion());
        $this->expectException(InboxException::class);
        $actions->reply($state, $admin, 'Duplicada', 'duplicate_private_test_1234');
    }

    public function testHumanReplyThroughHttpIsQueuedOnceAndTargetsOriginalConversation(): void
    {
        $this->client->disableReboot();
        $conversation = $this->conversation();
        $this->inbound($conversation, 'http-reply-inbound');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $crawler = $this->client->request('GET', '/s/atendimento');
        $csrf = $crawler->filter('#inbox-app')->attr('data-csrf');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf];
        $this->client->request('POST', '/s/atendimento/api/conversas/'.$state->getId().'/assumir', [], [], $headers, json_encode(['version' => $state->getVersion()]));
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['can_reply']);
        $body = json_encode(['body' => 'Resposta de teste isolado', 'request_id' => 'http_safe_reply_123456789']);
        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/s/atendimento/api/conversas/'.$state->getId().'/responder', [], [], $headers, $body);
            self::assertResponseIsSuccessful();
        }
        $jobs = $this->em->getRepository(MetaOutboundJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertSame('offline-user', $jobs[0]->getPayload()['recipient']);
        self::assertSame('Resposta de teste isolado', $jobs[0]->getPayload()['text']);
    }

    public function testLiveUpdateTokenChangesWhenDeliveryStatusChanges(): void
    {
        $conversation = $this->conversation();
        $message = $this->inbound($conversation, 'token-status');
        $updates = static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Application\InboxUpdates::class);
        $before = $updates->token();
        self::assertSame($before, $updates->token());
        $message->setStatus('read'); $this->em->persist($message); $this->em->flush();
        self::assertNotSame($before, $updates->token());
    }

    public function testHumanWhatsAppReplyPreservesInboundWaIdAndPersistsAcceptedResult(): void
    {
        $conversation = $this->conversation();
        $asset = $conversation->getAsset()->setType(AssetType::WhatsAppPhoneNumber);
        $conversation->setChannel('whatsapp')->setRecipient('553184326486');
        $inbound = (new MetaMessage())->setAsset($asset)->setConversation($conversation)->setChannel('whatsapp')->setDirection('inbound')->setMessageType('text')->setRecipient('553184326486')->setExternalId('verified-wa-inbound')->setPayload(['message' => ['text' => ['body' => 'Oi']]])->setStatus('received');
        foreach ([$asset, $conversation, $inbound] as $entity) { $this->em->persist($entity); } $this->em->flush();
        $graph = $this->createMock(\MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with($asset->getConnection(), $asset->getExternalId().'/messages', self::callback(fn(array $payload): bool => '553184326486' === $payload['to'] && 'Resposta humana' === $payload['text']['body']))->willReturn(['messages' => [['id' => 'offline-wamid-accepted', 'message_status' => 'accepted']]]);
        $sender = new \MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender($graph, $this->em, new \MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer(), static::getContainer()->get(\MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager::class), static::getContainer()->get(\MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy::class));
        $sender->sendText($asset, '553184326486', 'Resposta humana', false, null, true);
        $saved = $this->em->getRepository(MetaMessage::class)->findOneBy(['externalId' => 'offline-wamid-accepted']);
        $this->em->refresh($saved);
        self::assertSame('accepted', $saved->getStatus());
        self::assertSame('553184326486', $saved->getRecipient());
        $identity = (new \MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity())->setAsset($asset)->setExternalId('553184326486')->setConsentStatus(\MauticPlugin\MauticMetaBundle\Domain\ConsentStatus::OptedOut);
        $this->em->persist($identity);$this->em->flush();
        $this->expectException(\DomainException::class);
        $sender->sendText($asset, '553184326486', 'Não deve sair', false, null, true);
    }

    private function conversation(): MetaConversation
    {
        $connection = (new MetaConnection())->setName('Offline test')->setAppId('offline-inbox-app')->setStatus('active');
        $asset = (new MetaAsset())->setConnection($connection)->setName('Offline Instagram')->setExternalId('offline-asset')->setType(AssetType::InstagramAccount)->setStatus('active');
        $asset->setIsPublished(true);
        $conversation = (new MetaConversation())->setAsset($asset)->setChannel('instagram')->setRecipient('offline-user');
        foreach ([$connection, $asset, $conversation] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        return $conversation;
    }

    private function inbound(MetaConversation $conversation, string $externalId): MetaMessage
    {
        $message = (new MetaMessage())->setAsset($conversation->getAsset())->setConversation($conversation)
            ->setChannel('instagram')->setDirection('inbound')->setMessageType('direct_message')
            ->setRecipient($conversation->getRecipient())->setExternalId($externalId)->setPayload(['text' => 'Preciso de ajuda'])->setStatus('received');
        $this->em->persist($message);
        $this->em->flush();
        static::getContainer()->get(MetaInboxIntegration::class)->messagePersisted($message);
        return $message;
    }

    public function testOldReplayDoesNotReopenResolvedConversationAndNewInboundInvalidatesStaleVersion(): void
    {
        $conversation = $this->conversation();
        $first = $this->inbound($conversation, 'offline-message-1');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $before = $state->getVersion();
        $second = $this->inbound($conversation, 'offline-message-2');
        $this->em->refresh($state);
        self::assertGreaterThan($before, $state->getVersion(), 'A new question must invalidate stale resolve/transfer requests.');
        $actor = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $actions = static::getContainer()->get(ConversationActions::class);
        $state = $actions->take($state, $actor, $state->getVersion());
        $state = $actions->transition($state, $actor, $state->getVersion(), 'resolve');
        static::getContainer()->get(MetaInboxIntegration::class)->messagePersisted($first);
        $this->em->refresh($state);
        self::assertSame('resolved', $state->getLifecycle(), 'Replaying an older message cannot reopen resolved work.');
        self::assertSame($second->getId(), $state->getLastInboundMessageId());
    }

    public function testInternalNotesAndSeparateDraftsNeverCreateOutboundJobs(): void
    {
        $conversation = $this->conversation();
        $this->inbound($conversation, 'offline-note-message');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $actor = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $actions = static::getContainer()->get(ConversationActions::class);
        $jobsBefore = $this->em->getRepository(MetaOutboundJob::class)->count([]);
        $actions->saveDraft($state, $actor, 'reply', 'Resposta privada em andamento');
        $actions->saveDraft($state, $actor, 'note', 'Discussão da equipe');
        $actions->note($state, $actor, 'Nota confidencial interna');
        self::assertSame($jobsBefore, $this->em->getRepository(MetaOutboundJob::class)->count([]));
        self::assertSame(2, $this->em->getRepository(Draft::class)->count(['conversation' => $conversation, 'user' => $actor]));
    }

    public function testTakeoverStaysPausedAfterReturningToQueue(): void
    {
        $conversation = $this->conversation();
        $this->inbound($conversation, 'offline-take-message');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $actor = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $actions = static::getContainer()->get(ConversationActions::class);
        $state = $actions->take($state, $actor, $state->getVersion());
        $state = $actions->transition($state, $actor, $state->getVersion(), 'unassign');
        self::assertNull($state->getAssignee());
        self::assertFalse(static::getContainer()->get(MetaInboxIntegration::class)->automationAllowed($conversation->getAsset(), $conversation->getRecipient()));
        $this->expectException(InboxException::class);
        $actions->take($state, $actor, $state->getVersion() - 1);
    }

    public function testPrivateReplyCannotBypassHumanTakeoverUsingCommentId(): void
    {
        $conversation = $this->conversation();
        $privateMessage = $this->inbound($conversation, 'offline-private-takeover');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $actor = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        static::getContainer()->get(ConversationActions::class)->take($state, $actor, $state->getVersion());
        $public = (new MetaConversation())->setAsset($conversation->getAsset())->setChannel('instagram')->setRecipient('comment:offline-comment');
        $this->em->persist($public);
        $message = (new MetaMessage())->setAsset($conversation->getAsset())->setConversation($public)
            ->setChannel('instagram')->setDirection('inbound')->setMessageType('comment')->setRecipient('offline-user')
            ->setExternalId('offline-comment')->setPayload(['commentId' => 'offline-comment', 'mediaId' => 'offline-post', 'text' => 'relatorio'])->setStatus('received');
        $this->em->persist($message); $this->em->flush();
        $integration = static::getContainer()->get(MetaInboxIntegration::class);
        $integration->messagePersisted($message);
        $integration->messagePersisted($privateMessage);
        $query = static::getContainer()->get(InboxQuery::class);
        self::assertCount(1, $query->conversations($actor, ['queue' => 'all', 'kind' => 'private'])['items']);
        self::assertCount(1, $query->conversations($actor, ['queue' => 'all', 'kind' => 'comments'])['items']);
        self::assertCount(1, $query->detail($state, $actor)['origins']);
        $sent = false;
        try {
            $integration->runAutomationGuarded($conversation->getAsset(), 'offline-comment', function () use (&$sent): void { $sent = true; });
        } catch (DomainException) {
        }
        self::assertFalse($sent, 'A comment ID must resolve to its author before checking private human takeover.');
        $reply = (new MetaMessage())->setAsset($conversation->getAsset())->setChannel('instagram')->setDirection('outbound')->setMessageType('private_reply')->setRecipient('offline-comment')->setExternalId('private-reply-log')->setPayload(['message' => ['text' => 'Relatório']])->setStatus('sent');
        $this->em->persist($reply); $this->em->flush();
        $recorded = static::getContainer()->get(\MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager::class)->record($reply);
        self::assertSame($public->getId(), $recorded->getId());
    }

    public function testHumanReplyIsIdempotentAndHistoryIncludesAutomaticReportWithoutHumanDuplicates(): void
    {
        $conversation = $this->conversation();
        $this->inbound($conversation, 'offline-reply-message');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $actor = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $actions = static::getContainer()->get(ConversationActions::class);
        $state = $actions->take($state, $actor, $state->getVersion());
        $request = $actions->reply($state, $actor, 'Resposta humana', 'offline-request-000001');
        $again = $actions->reply($state, $actor, 'Resposta humana', 'offline-request-000001');
        self::assertSame($request->getId(), $again->getId());
        self::assertSame(1, $this->em->getRepository(MetaOutboundJob::class)->count(['idempotencyKey' => 'inbox:offline-request-000001']));
        $human = (new MetaMessage())->setAsset($conversation->getAsset())->setConversation($conversation)->setChannel('instagram')->setDirection('outbound')->setMessageType('direct_message')->setRecipient('offline-user')->setExternalId('human-confirmed')->setPayload(['message' => ['text' => 'Resposta humana']])->setStatus('sent');
        $auto = (new MetaMessage())->setAsset($conversation->getAsset())->setConversation($conversation)->setChannel('instagram')->setDirection('outbound')->setMessageType('direct_message')->setRecipient('offline-user')->setExternalId('automatic-confirmed')->setPayload(['message' => ['text' => 'Relatório da rodada']])->setStatus('sent');
        $this->em->persist($human); $this->em->persist($auto); $this->em->flush();
        $request->getJob()->setMessageLogId($human->getId())->setStatus('completed');
        $this->em->persist($request->getJob()); $this->em->flush();
        static::getContainer()->get(MetaInboxIntegration::class)->outboundJobChanged($request->getJob());
        $items = static::getContainer()->get(InboxQuery::class)->timeline($state, null, 40)['items'];
        self::assertCount(1, array_filter($items, static fn(array $item): bool => ($item['body'] ?? '') === 'Resposta humana'));
        self::assertCount(1, array_filter($items, static fn(array $item): bool => 'automatic' === $item['kind'] && 'Relatório da rodada' === $item['body']));
        $state = $actions->transition($state, $actor, $state->getVersion(), 'resolve');
        $request->getJob()->setStatus('uncertain');
        static::getContainer()->get(MetaInboxIntegration::class)->outboundJobChanged($request->getJob());
        $this->em->refresh($state);
        self::assertTrue($state->needsResponse());
        self::assertSame('open', $state->getLifecycle());
    }

    public function testReconcileDoesNotCreateGhostPrivateConversationsOrIncrementUnreadOnReplay(): void
    {
        $old = $this->conversation();
        $comment = (new MetaMessage())->setAsset($old->getAsset())->setConversation($old)->setChannel('instagram')->setDirection('inbound')->setMessageType('comment')->setRecipient('offline-user')->setExternalId('migration-comment')->setPayload(['commentId' => 'migration-comment', 'mediaId' => 'migration-post', 'text' => 'relatorio'])->setStatus('received');
        $this->em->persist($comment); $this->em->flush();
        $command = static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Command\ReconcileCommand::class);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--asset-id' => $old->getAsset()->getId(), '--apply' => true]));
        self::assertNull($this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $old]));
        $public = $comment->getConversation();
        self::assertSame('comment:migration-comment', $public->getRecipient());
        $unread = $public->getUnreadCount();
        self::assertSame(0, $tester->execute(['--asset-id' => $old->getAsset()->getId(), '--apply' => true]));
        $this->em->refresh($public);
        self::assertSame($unread, $public->getUnreadCount());
        self::assertSame(1, $this->em->getRepository(ConversationState::class)->count([]));
    }

    public function testOutboundStartedConversationIsVisibleWithoutInventingAnInboundQuestion(): void
    {
        $conversation = $this->conversation();
        $message = (new MetaMessage())->setAsset($conversation->getAsset())->setChannel('instagram')->setDirection('outbound')->setMessageType('direct_message')->setRecipient('offline-user')->setExternalId('outbound-first')->setPayload(['text' => 'Seu relatório'])->setStatus('sent');
        $this->em->persist($message); $this->em->flush();
        static::getContainer()->get(\MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager::class)->record($message);
        $this->em->refresh($message);
        self::assertSame($conversation->getId(), $message->getConversation()?->getId());
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        self::assertInstanceOf(ConversationState::class, $state);
        self::assertFalse($state->needsResponse());
        self::assertNull($state->getLastInboundMessageId());
        self::assertFalse($state->isHumanTakeover());
    }

    public function testReconcilePersistsOrphanMessagesAndIsRepeatable(): void
    {
        $conversation = $this->conversation();
        $message = (new MetaMessage())->setAsset($conversation->getAsset())->setChannel('instagram')->setDirection('inbound')->setMessageType('text')->setRecipient('offline-user')->setExternalId('orphan-first')->setPayload(['text' => 'Olá'])->setStatus('received');
        $this->em->persist($message); $this->em->flush();
        $tester = new \Symfony\Component\Console\Tester\CommandTester(static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Command\ReconcileCommand::class));
        self::assertSame(0, $tester->execute(['--asset-id' => $conversation->getAsset()->getId(), '--apply' => true]));
        $this->em->refresh($message);
        self::assertSame($conversation->getId(), $message->getConversation()?->getId());
        $unread = $conversation->getUnreadCount();
        self::assertSame(0, $tester->execute(['--asset-id' => $conversation->getAsset()->getId(), '--apply' => true]));
        $this->em->refresh($conversation);
        self::assertSame($unread, $conversation->getUnreadCount());
        self::assertEquals($message->getDateAdded(), $conversation->getLastInboundAt());
        self::assertEquals($message->getDateAdded(), $conversation->getLastMessageAt());
        self::assertSame(1, $this->em->getRepository(ConversationState::class)->count([]));
    }

    public function testTimelinePaginationDoesNotDropMessagesWithSameTimestamp(): void
    {
        $conversation = $this->conversation();
        $first = $this->inbound($conversation, 'offline-page-first');
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        $timestamp = new DateTimeImmutable('2026-01-01T10:00:00Z');
        $dateProperty = new ReflectionProperty(MetaMessage::class, 'dateAdded');
        $dateProperty->setValue($first, $timestamp);
        $this->em->persist($first);
        for ($i = 0; $i < 59; ++$i) {
            $message = (new MetaMessage())->setAsset($conversation->getAsset())->setConversation($conversation)
                ->setChannel('instagram')->setDirection('inbound')->setMessageType('direct_message')
                ->setRecipient('offline-user')->setExternalId('offline-page-'.$i)->setPayload(['text' => 'Mensagem '.$i])->setStatus('received');
            $dateProperty->setValue($message, $timestamp);
            $this->em->persist($message);
        }
        $this->em->flush();
        $query = static::getContainer()->get(InboxQuery::class);
        $cursor = null;
        $ids = [];
        for ($page = 0; $page < 5; ++$page) {
            $result = $query->timeline($state, $cursor, 25);
            foreach ($result['items'] as $item) {
                if ('message' === $item['kind']) { $ids[] = $item['id']; }
            }
            $cursor = $result['next_cursor'];
            if (null === $cursor) { break; }
        }
        self::assertCount(60, array_unique($ids), 'Pagination must include every message even when timestamps coincide.');
    }
}
