<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticInboxBundle\Entity\Draft;
use MauticPlugin\MauticInboxBundle\Entity\DraftRepository;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Entity\Note;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequestRepository;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\Queue\ImmediateOutboundDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;

final class ConversationActions
{
    public function __construct(
        private ConversationStateRepository $states,
        private DraftRepository $drafts,
        private OutboundRequestRepository $outboundRequests,
        private MetaMessageRepository $messages,
        private OutboundQueue $queue,
        private EntityManagerInterface $entityManager,
        private MetaInboxIntegration $inboxIntegration,
        private ReplyAvailability $replyAvailability,
        private WhatsAppTemplates $templates,
        private ImmediateOutboundDispatcher $immediateDispatcher,
    ) {
    }

    public function take(ConversationState $state, User $user, int $version): ConversationState
    {
        return $this->inboxIntegration->runHumanTransition($state, fn (): ConversationState => $this->takeLocked($state, $user, $version));
    }

    private function takeLocked(ConversationState $state, User $user, int $version): ConversationState
    {
        $updated = $this->entityManager->createQueryBuilder()->update(ConversationState::class, 's')
            ->set('s.assignee', ':user')->set('s.humanTakeover', ':yes')->set('s.lifecycle', ':open')
            ->set('s.snoozedUntil', ':none')->set('s.version', 's.version + 1')->set('s.dateModified', ':now')
            ->where('s.id = :id')->andWhere('s.version = :version')->andWhere('(s.assignee IS NULL OR s.assignee = :user)')
            ->setParameters(['user' => $user, 'yes' => true, 'open' => 'open', 'none' => null, 'now' => new \DateTimeImmutable(), 'id' => $state->getId(), 'version' => $version])
            ->getQuery()->execute();
        if (1 !== $updated) {
            throw new InboxException('mautic.inbox.ui.this_conversation_has_already_been_assigned_or_changed_by_someone_7ef320', 409);
        }
        $this->entityManager->clear(ConversationState::class);
        $fresh = $this->states->find($state->getId());
        $this->pauseAi($fresh);
        $this->log($fresh, $user, 'taken');

        return $fresh;
    }

    public function transition(ConversationState $state, User $actor, int $version, string $action, ?User $target = null, ?\DateTimeImmutable $until = null): ConversationState
    {
        return $this->inboxIntegration->runHumanTransition($state, fn (): ConversationState => $this->transitionLocked($state, $actor, $version, $action, $target, $until));
    }

    private function transitionLocked(ConversationState $state, User $actor, int $version, string $action, ?User $target = null, ?\DateTimeImmutable $until = null): ConversationState
    {
        if (!in_array($action, ['transfer', 'resolve', 'reopen', 'snooze', 'unassign'], true)) {
            throw new InboxException('mautic.inbox.ui.invalid_action_2ad361');
        }
        if ('transfer' === $action && !$target instanceof User) {
            throw new InboxException('mautic.inbox.ui.choose_a_person_to_transfer_the_conversation_to_757f37');
        }
        if ('snooze' === $action && (!$until instanceof \DateTimeImmutable || $until <= new \DateTimeImmutable())) {
            throw new InboxException('mautic.inbox.ui.choose_a_future_date_to_snooze_the_conversation_8f1066');
        }
        if ('reopen' !== $action && $state->getAssignee()?->getId() !== $actor->getId()) {
            throw new InboxException('mautic.inbox.ui.assign_the_conversation_to_yourself_before_making_changes_7f355a', 409);
        }

        $qb = $this->entityManager->createQueryBuilder()->update(ConversationState::class, 's')
            ->set('s.version', 's.version + 1')->set('s.dateModified', ':now')->where('s.id = :id')->andWhere('s.version = :version')
            ->setParameter('now', new \DateTimeImmutable())->setParameter('id', $state->getId())->setParameter('version', $version);
        if ('reopen' !== $action) { $qb->andWhere('s.assignee = :actor')->setParameter('actor', $actor); }
        match ($action) {
            'transfer' => $qb->set('s.assignee', ':target')->set('s.humanTakeover', ':yes')->set('s.lifecycle', ':open')->set('s.snoozedUntil', ':none')->setParameter('target', $target)->setParameter('yes', true)->setParameter('open', 'open')->setParameter('none', null),
            'resolve' => $qb->set('s.lifecycle', ':resolved')->set('s.needsResponse', ':no')->set('s.snoozedUntil', ':none')->setParameter('resolved', 'resolved')->setParameter('no', false)->setParameter('none', null),
            'reopen' => $qb->set('s.lifecycle', ':open')->set('s.snoozedUntil', ':none')->setParameter('open', 'open')->setParameter('none', null),
            'snooze' => $qb->set('s.lifecycle', ':snoozed')->set('s.snoozedUntil', ':until')->setParameter('snoozed', 'snoozed')->setParameter('until', $until),
            'unassign' => $qb->set('s.assignee', ':none')->set('s.lifecycle', ':open')->set('s.snoozedUntil', ':none')->setParameter('none', null)->setParameter('open', 'open'),
        };
        if (1 !== $qb->getQuery()->execute()) {
            throw new InboxException('A conversa foi alterada por outra pessoa. Atualize a tela e tente novamente.', 409);
        }
        $this->entityManager->clear(ConversationState::class);
        $fresh = $this->states->find($state->getId());
        $this->pauseAi($fresh);
        $this->log($fresh, $actor, $action, ['target_user_id' => $target?->getId(), 'until' => $until?->format(DATE_ATOM)]);

        return $fresh;
    }

    public function note(ConversationState $state, User $author, string $body): Note
    {
        $body = $this->body($body);
        $note = (new Note())->setConversation($state->getConversation())->setAuthor($author)->setBody($body);
        $this->entityManager->persist($note);
        $draft = $this->drafts->findOneBy(['conversation' => $state->getConversation(), 'user' => $author, 'mode' => 'note']);
        if ($draft instanceof Draft) { $draft->setBody(''); $this->entityManager->persist($draft); }
        $this->entityManager->flush();

        return $note;
    }

    public function saveDraft(ConversationState $state, User $user, string $mode, string $body): Draft
    {
        if (!in_array($mode, ['reply', 'note'], true)) {
            throw new InboxException('mautic.inbox.ui.invalid_draft_mode_5e9827');
        }
        if (mb_strlen($body) > 4000) {
            throw new InboxException('mautic.inbox.ui.the_draft_can_contain_at_most_4_000_characters_ec73b6');
        }
        $draft = $this->drafts->findOneBy(['conversation' => $state->getConversation(), 'user' => $user, 'mode' => $mode]);
        if (!$draft instanceof Draft) {
            $draft = (new Draft())->setConversation($state->getConversation())->setUser($user)->setMode($mode);
        }
        $draft->setBody($body);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    public function reply(ConversationState $state, User $author, string $body, string $requestId, ?array $template = null): OutboundRequest
    {
        $outbound = $this->inboxIntegration->runHumanTransition($state, fn (): OutboundRequest => $this->entityManager->wrapInTransaction(function () use ($state, $author, $body, $requestId, $template): OutboundRequest {
                $locked = $this->entityManager->find(ConversationState::class, $state->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ConversationState) {
                    throw new InboxException('mautic.inbox.ui.conversation_not_found_61bc81', 404);
                }

                $this->entityManager->refresh($locked, LockMode::PESSIMISTIC_WRITE);
                return $this->replyLocked($locked, $author, $body, $requestId, $template);
            }));
        if ($outbound->getJob()) {
            // The local request and conversation state are committed before any
            // external API call. Only human WhatsApp text is eligible; templates
            // and automation remain on the durable queue.
            $this->immediateDispatcher->dispatch($outbound->getJob());
        }

        return $outbound;
    }

    public function retry(OutboundRequest $failedRequest, User $author, string $requestId): OutboundRequest
    {
        $state = $this->states->findOneBy(['conversation' => $failedRequest->getConversation()]);
        if (!$state instanceof ConversationState) {
            throw new InboxException('mautic.inbox.ui.conversation_not_found_61bc81', 404);
        }

        [$outbound, $created] = $this->inboxIntegration->runHumanTransition($state, fn (): array => $this->entityManager->wrapInTransaction(function () use ($state, $failedRequest, $author, $requestId): array {
            $lockedState = $this->entityManager->find(ConversationState::class, $state->getId(), LockMode::PESSIMISTIC_WRITE);
            $lockedRequest = $this->entityManager->find(OutboundRequest::class, $failedRequest->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedState instanceof ConversationState || !$lockedRequest instanceof OutboundRequest || $lockedRequest->getConversation()->getId() !== $lockedState->getConversation()->getId()) {
                throw new InboxException('mautic.inbox.ui.retry_source_not_found', 404);
            }

            $this->entityManager->refresh($lockedState, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($lockedRequest, LockMode::PESSIMISTIC_WRITE);

            return $this->retryLocked($lockedState, $lockedRequest, $author, $requestId);
        }));
        if ($created && $outbound->getJob()) {
            $this->immediateDispatcher->dispatch($outbound->getJob());
        }

        return $outbound;
    }

    /** @return array{OutboundRequest,bool} */
    private function retryLocked(ConversationState $state, OutboundRequest $failedRequest, User $author, string $requestId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestId)) {
            throw new InboxException('mautic.inbox.ui.invalid_send_identifier_6d8e69');
        }
        $existing = $this->outboundRequests->findOneBy(['requestId' => $requestId]);
        if ($existing instanceof OutboundRequest) {
            if ($existing->getConversation()->getId() !== $state->getConversation()->getId()
                || $existing->getAuthor()->getId() !== $author->getId()
                || $existing->getBody() !== $failedRequest->getBody()
                || ($existing->getJob()?->getPayload()['_retry_of'] ?? null) !== $failedRequest->getRequestId()) {
                throw new InboxException('mautic.inbox.ui.send_identifier_already_used_419540', 409);
            }

            return [$existing, false];
        }
        if ('failed' !== $failedRequest->getStatus() || !$failedRequest->getJob()) {
            throw new InboxException('mautic.inbox.ui.retry_failed_only', 409);
        }
        if ($state->getAssignee()?->getId() !== $author->getId()) {
            throw new InboxException('mautic.inbox.ui.assign_the_conversation_to_yourself_before_replying_52b660', 409);
        }
        if ('resolved' === $state->getLifecycle()) {
            throw new InboxException('mautic.inbox.ui.reopen_the_conversation_before_replying_cd5de7', 409);
        }
        $asset = $state->getConversation()->getAsset();
        if ('active' !== $asset->getStatus() || !$asset->isPublished()) {
            throw new InboxException('mautic.inbox.ui.this_conversation_s_channel_is_not_available_for_sending_8627bd', 409);
        }

        $oldJob = $failedRequest->getJob();
        $oldPayload = $oldJob->getPayload();
        $operation = $oldJob->getOperation();
        if ($oldJob->getAsset()->getId() !== $asset->getId()) {
            throw new InboxException('mautic.inbox.ui.retry_target_changed', 409);
        }
        if ('whatsapp_template' === $operation) {
            if (null !== ($reason = $this->templates->blockedReason($state))) {
                throw new InboxException($reason, 409);
            }
            $templateId = (int) ($oldPayload['_template_id'] ?? 0);
            $available = array_filter($this->templates->catalog($state), static fn (array $template): bool => $template['id'] === $templateId && $template['supported']);
            if (!$available) {
                throw new InboxException('mautic.inbox.template.unavailable', 409);
            }
            $payload = array_intersect_key($oldPayload, array_flip(['recipient', 'name', 'language', 'components', '_template_id']));
        } else {
            if (null !== ($reason = $this->replyAvailability->reason($state))) {
                throw new InboxException($reason, 409);
            }
            [$currentOperation, $recipient] = $this->outboundTarget($state->getConversation());
            if ($currentOperation !== $operation || (string) ($oldPayload['recipient'] ?? '') !== $recipient) {
                throw new InboxException('mautic.inbox.ui.retry_target_changed', 409);
            }
            $payload = ['recipient' => $recipient, 'text' => $failedRequest->getBody()];
        }
        $job = $this->queue->enqueue($asset, $operation, $payload + [
            '_origin' => 'inbox_human',
            '_inbox_conversation_id' => $state->getConversation()->getId(),
            '_retry_of' => $failedRequest->getRequestId(),
        ], $state->getConversation()->getContact(), 1, 'inbox:'.$requestId);
        $request = (new OutboundRequest())
            ->setConversation($state->getConversation())
            ->setAuthor($author)
            ->setRequestId($requestId)
            ->setBody($failedRequest->getBody())
            ->setJob($job)
            ->setStatus('pending');
        $state->setNeedsResponse(false)->setHumanTakeover(true)->setVersion($state->getVersion() + 1);
        $this->entityManager->persist($request);
        $this->entityManager->persist($state);
        $this->entityManager->persist((new EventLog())->setConversation($state->getConversation())->setActor($author)->setEventType('reply_retried')->setDetails([
            'request_id' => $requestId,
            'retry_of' => $failedRequest->getRequestId(),
        ]));
        $this->entityManager->flush();

        return [$request, true];
    }

    private function replyLocked(ConversationState $state, User $author, string $body, string $requestId, ?array $template = null): OutboundRequest
    {
        if ($state->getAssignee()?->getId() !== $author->getId()) {
            throw new InboxException('mautic.inbox.ui.assign_the_conversation_to_yourself_before_replying_52b660', 409);
        }
        if ('resolved' === $state->getLifecycle()) {
            throw new InboxException('mautic.inbox.ui.reopen_the_conversation_before_replying_cd5de7', 409);
        }
        $asset = $state->getConversation()->getAsset();
        if ('active' !== $asset->getStatus() || !$asset->isPublished()) {
            throw new InboxException('mautic.inbox.ui.this_conversation_s_channel_is_not_available_for_sending_8627bd', 409);
        }
        $prepared = $template ? $this->templates->prepare($state, (int) ($template['id'] ?? 0), (array) ($template['variables'] ?? [])) : null;
        $body = $this->body($prepared['body'] ?? $body);
        if ('facebook' === $state->getConversation()->getChannel() && mb_strlen($body) > 2000) { throw new InboxException('mautic.inbox.ui.facebook_replies_can_contain_at_most_2_000_characters_0cb37d'); }
        if ('instagram' === $state->getConversation()->getChannel() && mb_strlen($body) > 1000) { throw new InboxException('mautic.inbox.ui.instagram_replies_can_contain_at_most_1_000_characters_80898c'); }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestId)) {
            throw new InboxException('mautic.inbox.ui.invalid_send_identifier_6d8e69');
        }
        $existing = $this->outboundRequests->findOneBy(['requestId' => $requestId]);
        if ($existing instanceof OutboundRequest) {
            if ($existing->getConversation()->getId() !== $state->getConversation()->getId() || $existing->getAuthor()->getId() !== $author->getId() || $existing->getBody() !== $body || ($existing->getJob()?->getPayload()['_template_id'] ?? null) !== ($prepared['payload']['_template_id'] ?? null) || ($existing->getJob()?->getPayload()['components'] ?? []) !== ($prepared['payload']['components'] ?? [])) {
                throw new InboxException('mautic.inbox.ui.send_identifier_already_used_419540', 409);
            }
            return $existing;
        }

        if (!$prepared && null !== ($reason = $this->replyAvailability->reason($state))) { throw new InboxException($reason, 409); }
        [$operation, $recipient] = $this->outboundTarget($state->getConversation());
        $job = $this->queue->enqueue($asset, $prepared ? 'whatsapp_template' : $operation, ($prepared['payload'] ?? []) + [
            'recipient' => $recipient,
            'text' => $body,
            '_origin' => 'inbox_human',
            '_inbox_conversation_id' => $state->getConversation()->getId(),
        ], $state->getConversation()->getContact(), 1, 'inbox:'.$requestId);
        $request = (new OutboundRequest())->setConversation($state->getConversation())->setAuthor($author)->setRequestId($requestId)->setBody($body)->setJob($job)->setStatus('pending');
        $state->setNeedsResponse(false)->setHumanTakeover(true)->setVersion($state->getVersion() + 1);
        $this->entityManager->persist($request);
        $this->entityManager->persist($state);
        $draft = $this->drafts->findOneBy(['conversation' => $state->getConversation(), 'user' => $author, 'mode' => 'reply']);
        if ($draft instanceof Draft) {
            $this->entityManager->remove($draft);
        }
        $this->entityManager->flush();
        $this->log(
            $state,
            $author,
            $this->immediateDispatcher->supports($job) ? 'reply_requested' : 'reply_queued',
            ['request_id' => $requestId],
        );

        return $request;
    }

    public function wakeDue(int $limit = 500): int
    {
        $due = $this->states->createQueryBuilder('s')->where('s.lifecycle = :status')->andWhere('s.snoozedUntil <= :now')
            ->setParameter('status', 'snoozed')->setParameter('now', new \DateTimeImmutable())->setMaxResults(max(1, min(1000, $limit)))->getQuery()->getResult();
        $changed = 0;
        foreach ($due as $state) {
            $updated = $this->entityManager->createQueryBuilder()->update(ConversationState::class, 's')
                ->set('s.lifecycle', ':open')->set('s.snoozedUntil', ':none')->set('s.version', 's.version + 1')->set('s.dateModified', ':now')
                ->where('s.id = :id')->andWhere('s.version = :version')->andWhere('s.lifecycle = :snoozed')->andWhere('s.snoozedUntil <= :now')
                ->setParameters(['open' => 'open', 'none' => null, 'now' => new \DateTimeImmutable(), 'id' => $state->getId(), 'version' => $state->getVersion(), 'snoozed' => 'snoozed'])->getQuery()->execute();
            if (1 !== $updated) { continue; }
            ++$changed;
            $this->entityManager->refresh($state);
            $this->entityManager->persist((new EventLog())->setConversation($state->getConversation())->setEventType('woken')->setDetails([]));
        }
        if ($changed > 0) { $this->entityManager->flush(); }
        return $changed;
    }

    private function pauseAi(ConversationState $state): void {
        $meta=$this->entityManager->getClassMetadata(\MauticPlugin\MauticInboxBundle\Entity\AiRecord::class);
        if(!$this->entityManager->getConnection()->createSchemaManager()->tablesExist([$meta->getTableName()]))return;
        $store=new \MauticPlugin\MauticInboxBundle\Application\Ai\AiStore($this->entityManager);$a=$store->get('assignment',(string)$state->getId());
        if($a){$a['status']='paused';$a['reason']='human';$a['nonce']=bin2hex(random_bytes(16));$store->put('assignment',(string)$state->getId(),$a);}
    }
    private function body(string $body): string
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > 4000) {
            throw new InboxException('mautic.inbox.ui.write_a_message_of_up_to_4_000_characters_7aa641');
        }
        return $body;
    }

    /** @return array{string,string} */
    private function outboundTarget(MetaConversation $conversation): array
    {
        if ('facebook' === $conversation->getChannel()) {
            return str_starts_with($conversation->getRecipient(), 'comment:') ? ['facebook_public_reply', substr($conversation->getRecipient(), 8)] : ['facebook_direct_message', $conversation->getRecipient()];
        }
        if ('whatsapp' === $conversation->getChannel()) {
            return ['whatsapp_text', $conversation->getRecipient()];
        }
        if (str_starts_with($conversation->getRecipient(), 'comment:')) {
            $alreadySent = $this->messages->findOneBy(['conversation' => $conversation, 'direction' => 'outbound', 'messageType' => 'private_reply', 'status' => ['accepted', 'sent', 'delivered', 'read', 'pending', 'processing', 'uncertain']]);
            if ($alreadySent instanceof MetaMessage || $this->outboundRequests->findOneBy(['conversation' => $conversation, 'status' => ['pending', 'processing', 'waiting', 'uncertain', 'sent', 'accepted', 'delivered', 'read']]) instanceof OutboundRequest) {
                throw new InboxException('mautic.inbox.ui.a_private_reply_has_already_been_sent_or_requested_wait_for_the_p_028b40', 409);
            }
            return ['instagram_private_reply', substr($conversation->getRecipient(), 8)];
        }
        $last = $this->messages->findOneBy(['conversation' => $conversation, 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC']);
        if ($last instanceof MetaMessage && 'comment' === $last->getMessageType()) {
            $commentId = trim((string) ($last->getPayload()['commentId'] ?? ''));
            if ('' === $commentId) {
                throw new InboxException('mautic.inbox.ui.the_original_comment_is_not_available_for_a_private_reply_d5a2ee', 409);
            }
            return ['instagram_private_reply', $commentId];
        }

        return ['instagram_direct_message', $conversation->getRecipient()];
    }

    /** @param array<string, scalar|null> $details */
    private function log(ConversationState $state, ?User $actor, string $type, array $details = []): void
    {
        $this->entityManager->persist((new EventLog())->setConversation($state->getConversation())->setActor($actor)->setEventType($type)->setDetails($details));
        $this->entityManager->flush();
    }
}
