<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Application\Webhook\FacebookWebhookProcessor;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use MauticPlugin\MauticInboxBundle\Application\ReplyAvailability;

final class FacebookInboxTest extends MauticMysqlTestCase
{
    public function testFacebookCommentLifecycleAndPublicReplyQueueAreIsolated(): void
    {
        $connection = (new MetaConnection())->setName('Facebook test')->setIsPublished(true);
        $this->em->persist($connection);
        $page = (new MetaAsset())->setConnection($connection)->setType(AssetType::FacebookPage)->setExternalId('1234')->setName('Test page')->setIsPublished(true)->setStatus('active')->setSettings(['facebook_read_enabled' => false]);
        $this->em->persist($page); $this->em->flush();
        $processor = static::getContainer()->get(FacebookWebhookProcessor::class);
        $payload = ['object' => 'page', 'entry' => [['id' => '1234', 'time' => time(), 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => '1234_555', 'post_id' => '1234_99', 'from' => ['id' => '888', 'name' => 'Maria'], 'message' => 'relatorio', 'created_time' => time(), 'origin_media' => ['kind' => 'reel', 'caption' => 'Reel da rodada']]]]]]];
        self::assertSame(1, $processor->process($payload, $connection)['created']);
        self::assertSame(0, $processor->process($payload, $connection)['created']);
        $message = $this->em->getRepository(MetaMessage::class)->findOneBy(['externalId' => '1234_555']);
        self::assertSame('facebook', $message->getChannel());
        self::assertSame('comment:1234_555', $message->getConversation()->getRecipient());
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $message->getConversation()]);
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $detail = static::getContainer()->get(InboxQuery::class)->detail($state, $user);
        self::assertSame('Maria', $detail['contact_name']);
        self::assertSame('Comentário em Reel', $detail['conversation_kind']);
        self::assertTrue($detail['reply_public']);
        self::assertTrue($detail['can_take_and_reply']);
        self::assertSame('1234_99', $detail['origins'][0]['media_id']);
        $actions = static::getContainer()->get(ConversationActions::class);
        $actions->take($state, $user, $state->getVersion());
        $actions->reply($state, $user, 'Resposta pública de teste', 'facebook-comment-request-1');
        $actions->reply($state, $user, 'Resposta pública de teste', 'facebook-comment-request-1');
        $jobs = $this->em->getRepository(MetaOutboundJob::class)->findBy(['asset' => $page]);
        self::assertCount(1, $jobs);
        self::assertSame('facebook_public_reply', $jobs[0]->getOperation());
        self::assertSame('1234_555', $jobs[0]->getPayload()['recipient']);
        $payload['entry'][0]['changes'][0]['value']['verb'] = 'edited';
        $payload['entry'][0]['changes'][0]['value']['message'] = 'relatorio atualizado';
        self::assertSame(1, $processor->process($payload, $connection)['updated']);
        self::assertSame('relatorio atualizado', $message->getPayload()['text']);
        $payload['entry'][0]['changes'][0]['value']['verb'] = 'remove';
        $processor->process($payload, $connection);
        self::assertNotNull(static::getContainer()->get(ReplyAvailability::class)->reason($state));
        $other = (new MetaConnection())->setName('Other')->setAppId('other-test-app')->setIsPublished(true); $this->em->persist($other); $this->em->flush();
        self::assertSame(1, $processor->process($payload, $other)['ignored']);
    }

    public function testMessengerUsesPrivateConversationAndPreservesAttachments(): void
    {
        $graph = $this->createMock(\MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface::class);
        $graph->method('get')->willThrowException(new \RuntimeException('Profile is unavailable in this offline fixture.'));
        static::getContainer()->set(\MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookParticipantProfile::class, new \MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookParticipantProfile($graph, new \MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver($graph, static::getContainer()->get(\MauticPlugin\MauticMetaBundle\Security\CredentialVault::class)), new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $connection = (new MetaConnection())->setName('Messenger test')->setIsPublished(true); $this->em->persist($connection);
        $page = (new MetaAsset())->setConnection($connection)->setType(AssetType::FacebookPage)->setExternalId('1234')->setName('Test page')->setIsPublished(true)->setStatus('active');
        $this->em->persist($page); $this->em->flush();
        $payload = ['object' => 'page', 'entry' => [['id' => '1234', 'messaging' => [['sender' => ['id' => '888'], 'recipient' => ['id' => '1234'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'm_fb_test', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://example.com/image.jpg']]]]]]]]];
        static::getContainer()->get(FacebookWebhookProcessor::class)->process($payload, $connection);
        $message = $this->em->getRepository(MetaMessage::class)->findOneBy(['externalId' => 'm_fb_test']);
        self::assertSame('888', $message->getConversation()->getRecipient());
        $state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $message->getConversation()]);
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $query = static::getContainer()->get(InboxQuery::class);
        self::assertFalse($query->detail($state, $user)['reply_public']);
        $messages = array_values(array_filter($query->timeline($state, null)['items'], fn($x) => 'message' === $x['kind']));
        self::assertSame('https://example.com/image.jpg', $messages[0]['attachments'][0]['url']);
        $message->setDateAdded(new \DateTimeImmutable('-25 hours')); $this->em->persist($message); $this->em->flush();
        self::assertStringContainsString('Messenger', static::getContainer()->get(ReplyAvailability::class)->reason($state));
    }
    public static function senderCases(): array
    {
        return ['public comment' => [true, false], 'Messenger' => [false, false], 'expired Messenger window' => [false, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('senderCases')]
    public function testFacebookSenderUsesPageCredentialAndCorrectEndpoint(bool $public, bool $expired): void
    {
        $connection = (new MetaConnection())->setName('Sender test')->setIsPublished(true); $this->em->persist($connection);
        $page = (new MetaAsset())->setConnection($connection)->setType(AssetType::FacebookPage)->setExternalId('1234')->setName('Test page')->setIsPublished(true)->setStatus('active')->setSettings(['facebook_read_enabled' => false]);
        $this->em->persist($page); $this->em->flush();
        $inbound = (new MetaMessage())->setAsset($page)->setChannel('facebook')->setDirection('inbound')->setMessageType($public ? 'comment' : 'direct_message')->setExternalId('1234_777')->setRecipient('888')->setPayload(['text' => 'relatorio', 'commentId' => '1234_777']);
        $inbound->setDateAdded(new \DateTimeImmutable($expired ? '-25 hours' : 'now'));
        $this->em->persist($inbound); $this->em->flush();
        $container = static::getContainer();
        $container->get(\MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager::class)->record($inbound);
        $vault = $container->get(\MauticPlugin\MauticMetaBundle\Security\CredentialVault::class);
        $graph = $this->createMock(\MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('get')->with($connection, 'me/accounts', self::anything())->willReturn(['data' => [['id' => '1234', 'access_token' => 'offline-page-token']]]);
        $graph->expects($expired ? self::never() : self::once())->method('post')->with(self::callback(fn($c) => $c !== $connection && $c->getEncryptedAccessToken() !== $connection->getEncryptedAccessToken()), $public ? '1234_777/comments' : '1234/messages', $public ? ['message' => 'Resposta pública'] : ['messaging_type' => 'RESPONSE', 'recipient' => ['id' => '888'], 'message' => ['text' => 'Resposta pública']])->willReturn(['id' => '1234_778']);
        $sender = new \MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookService($graph,
            new \MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver($graph, $vault), $this->em,
            $container->get(\MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager::class),
            $container->get(\MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy::class),
            $container->get(\MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager::class),
            $container->get(\MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface::class));
        if ($expired) { $this->expectException(\DomainException::class); }
        $sent = $sender->send($page, $public ? '1234_777' : '888', 'Resposta pública', $public, null, true);
        $id = $sent->getId(); $this->em->clear(); $stored = $this->em->find(MetaMessage::class, $id);
        self::assertSame('accepted', $stored->getStatus());
        self::assertSame($public ? 'comment:1234_777' : '888', $stored->getConversation()->getRecipient());
        self::assertSame('facebook', $stored->getChannel());
    }

}
