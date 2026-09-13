<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Integration;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\CommentContext;
use MauticPlugin\MauticInboxBundle\Entity\CommentContextRepository;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequestRepository;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

final class MetaInboxIntegration implements InboxIntegrationInterface
{
    public function __construct(
        private ConversationStateRepository $states,
        private CommentContextRepository $comments,
        private OutboundRequestRepository $outboundRequests,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ownsSupportInbox(): bool
    {
        return true;
    }

    public function messagePersisted(MetaMessage $message): void
    {
        $conversation = $message->getConversation();
        if (null === $conversation) {
            return;
        }

        $this->withLocks($this->lockKeys($message->getAsset(), $conversation->getRecipient()), function () use ($message, $conversation): void {
            if ('inbound' === $message->getDirection()) { $this->persistInbound($message); return; }
            if (!$this->states->findOneBy(['conversation' => $conversation]) instanceof ConversationState) {
                $state = (new ConversationState())->setConversation($conversation)->setNeedsResponse(in_array($message->getStatus(), ['failed', 'uncertain'], true));
                $this->entityManager->persist($state);
                $this->entityManager->flush();
            }
        });
    }

    private function persistInbound(MetaMessage $message): void
    {
        $conversation = $message->getConversation();
        if (null === $conversation) { return; }

        $state = $this->states->findOneBy(['conversation' => $conversation]);
        $created = false;
        if (!$state instanceof ConversationState) {
            $state = (new ConversationState())->setConversation($conversation);
            $this->entityManager->persist($state);
            $created = true;
        }
        if (in_array($message->getChannel(), ['instagram', 'facebook'], true) && 'comment' === $message->getMessageType()) {
            $this->recordCommentContext($message);
        } elseif (in_array($message->getChannel(), ['instagram', 'facebook'], true) && in_array($message->getMessageType(), ['direct_message', 'postback'], true)) {
            foreach ($this->comments->findBy(['participantId' => $message->getRecipient(), 'privateConversation' => null]) as $context) {
                if ($context->getPublicConversation()->getAsset()->getId() !== $message->getAsset()->getId()) { continue; }
                $context->setPrivateConversation($conversation);
                $this->entityManager->persist($context);
            }
        }
        if (null !== $state->getLastInboundMessageId() && (int) $message->getId() <= $state->getLastInboundMessageId()) {
            $this->entityManager->flush();
            return;
        }
        $state->setLastInboundMessageId($message->getId())->setNeedsResponse(true)->setLifecycle('open')->setSnoozedUntil(null);
        if (!$created) {
            $state->setVersion($state->getVersion() + 1);
        }
        $this->entityManager->persist($state);
        $event = (new EventLog())->setConversation($conversation)->setEventType($created ? 'created' : 'reopened')->setDetails(['message_id' => $message->getId()]);
        $this->entityManager->persist($event);

        $this->entityManager->flush();
    }

    public function automationAllowed(MetaAsset $asset, string $recipient): bool
    {
        if ('' === trim($recipient)) {
            return false;
        }
        $commentId = str_starts_with($recipient, 'comment:') ? substr($recipient, 8) : $recipient;
        $context = $this->comments->findOneBy(['commentId' => $commentId]);
        $participant = $context instanceof CommentContext && $context->getPublicConversation()->getAsset()->getId() === $asset->getId()
            ? $context->getParticipantId()
            : $recipient;
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')->from(ConversationState::class, 's')->join('s.conversation', 'c')
            ->where('c.asset = :asset')->andWhere('(c.recipient = :recipient OR c.recipient = CONCAT(:commentPrefix, :recipient) OR c.recipient = :participant)')->andWhere('s.humanTakeover = :takeover')
            ->setParameter('asset', $asset)->setParameter('recipient', $recipient)->setParameter('takeover', true)
            ->setParameter('participant', $participant)
            ->setParameter('commentPrefix', 'comment:')
            ->getQuery()->getSingleScalarResult();

        if ($count > 0) {
            return false;
        }
        $commentCount = (int) $this->entityManager->createQueryBuilder()->select('COUNT(s2.id)')->from(ConversationState::class, 's2')
            ->join('s2.conversation', 'pc')->from(CommentContext::class, 'cc')->where('cc.publicConversation = pc')
            ->andWhere('pc.asset = :asset')->andWhere('cc.participantId = :recipient')->andWhere('s2.humanTakeover = :takeover')
            ->setParameter('asset', $asset)->setParameter('recipient', $recipient)->setParameter('takeover', true)->getQuery()->getSingleScalarResult();

        return 0 === $commentCount;
    }

    public function runAutomationGuarded(MetaAsset $asset, string $recipient, callable $operation): mixed
    {
        return $this->withLocks($this->lockKeys($asset, $recipient), function () use ($asset, $recipient, $operation): mixed {
            if (!$this->automationAllowed($asset, $recipient)) {
                throw new \DomainException('Automation paused while this conversation is assigned to human support.');
            }

            return $operation();
        });
    }

    public function runHumanTransition(ConversationState $state, callable $operation): mixed
    {
        return $this->withLocks($this->lockKeys($state->getConversation()->getAsset(), $state->getConversation()->getRecipient()), $operation);
    }

    public function outboundJobChanged(MetaOutboundJob $job): void
    {
        $request = $this->outboundRequests->findOneBy(['job' => $job]);
        if (!$request instanceof OutboundRequest) {
            return;
        }
        $request->setStatus(match ($job->getStatus()) {
            'completed' => 'sent',
            'failed', 'blocked', 'uncertain' => 'failed',
            'processing' => 'processing',
            'retry' => 'waiting',
            default => 'pending',
        });
        if (in_array($job->getStatus(), ['failed', 'blocked', 'uncertain'], true)) {
            $request->setFailureReason('Não foi possível enviar. Revise a conversa antes de tentar novamente.');
        }
        $this->entityManager->persist($request);
        if (in_array($job->getStatus(), ['failed', 'blocked', 'uncertain'], true)) {
            $state = $this->states->findOneBy(['conversation' => $request->getConversation()]);
            if ($state instanceof ConversationState) {
                $state->setNeedsResponse(true)->setLifecycle('open')->setSnoozedUntil(null)->setVersion($state->getVersion() + 1);
                $this->entityManager->persist($state);
                $this->entityManager->persist((new EventLog())->setConversation($request->getConversation())->setEventType('reply_failed')->setDetails(['request_id' => $request->getRequestId()]));
            }
        }
        $this->entityManager->flush();
    }

    private function recordCommentContext(MetaMessage $message): void
    {
        if ($this->comments->findOneBy(['message' => $message]) instanceof CommentContext) {
            return;
        }
        $payload = $message->getPayload();
        $context = (new CommentContext())
            ->setMessage($message)
            ->setPublicConversation($message->getConversation())
            ->setAccountId((string) ($payload['accountId'] ?? $message->getAsset()->getExternalId()))
            ->setMediaId((string) ($payload['mediaId'] ?? ''))
            ->setCommentId((string) ($payload['commentId'] ?? $message->getExternalId() ?? ''))
            ->setParticipantId($message->getRecipient())
            ->setPermalink(isset($payload['permalink']) ? (string) $payload['permalink'] : null);
        $this->entityManager->persist($context);
    }

    /** @return list<string> */
    private function lockKeys(MetaAsset $asset, string $recipient): array
    {
        $identities = [$recipient];
        $commentId = str_starts_with($recipient, 'comment:') ? substr($recipient, 8) : $recipient;
        $context = $this->comments->findOneBy(['commentId' => $commentId]);
        if ($context instanceof CommentContext && $context->getPublicConversation()->getAsset()->getId() === $asset->getId()) {
            $identities[] = $context->getParticipantId();
            $identities[] = 'comment:'.$context->getCommentId();
        }
        $keys = array_map(static fn (string $identity): string => 'inbox_'.substr(hash('sha256', $asset->getId().':'.$identity), 0, 48), array_unique($identities));
        sort($keys);
        return $keys;
    }

    private function withLocks(array $keys, callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $acquired = [];
        try {
            foreach ($keys as $key) {
                if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(:lock_name, 35)', ['lock_name' => $key])) {
                    throw new \RuntimeException('Não foi possível bloquear a conversa para esta ação. Tente novamente.');
                }
                $acquired[] = $key;
            }
            return $operation();
        } finally {
            foreach (array_reverse($acquired) as $key) {
                $connection->fetchOne('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $key]);
            }
        }
    }
}
