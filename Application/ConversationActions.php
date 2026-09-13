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
            throw new InboxException('Esta conversa já foi assumida ou alterada por outra pessoa.', 409);
        }
        $this->entityManager->clear(ConversationState::class);
        $fresh = $this->states->find($state->getId());
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
            throw new InboxException('Ação inválida.');
        }
        if ('transfer' === $action && !$target instanceof User) {
            throw new InboxException('Escolha uma pessoa para transferir.');
        }
        if ('snooze' === $action && (!$until instanceof \DateTimeImmutable || $until <= new \DateTimeImmutable())) {
            throw new InboxException('Escolha uma data futura para adiar.');
        }
        if ('reopen' !== $action && $state->getAssignee()?->getId() !== $actor->getId()) {
            throw new InboxException('Assuma a conversa antes de alterar o atendimento.', 409);
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
            throw new InboxException('Modo de rascunho inválido.');
        }
        if (mb_strlen($body) > 4000) {
            throw new InboxException('O rascunho pode ter no máximo 4.000 caracteres.');
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

    public function reply(ConversationState $state, User $author, string $body, string $requestId): OutboundRequest
    {
        return $this->inboxIntegration->runHumanTransition($state, fn (): OutboundRequest => $this->entityManager->wrapInTransaction(function () use ($state, $author, $body, $requestId): OutboundRequest {
                $locked = $this->entityManager->find(ConversationState::class, $state->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ConversationState) {
                    throw new InboxException('Conversa não encontrada.', 404);
                }

                $this->entityManager->refresh($locked, LockMode::PESSIMISTIC_WRITE);
                return $this->replyLocked($locked, $author, $body, $requestId);
            }));
    }

    private function replyLocked(ConversationState $state, User $author, string $body, string $requestId): OutboundRequest
    {
        if ($state->getAssignee()?->getId() !== $author->getId()) {
            throw new InboxException('Assuma a conversa antes de responder.', 409);
        }
        if ('resolved' === $state->getLifecycle()) {
            throw new InboxException('Reabra a conversa antes de responder.', 409);
        }
        $asset = $state->getConversation()->getAsset();
        if ('active' !== $asset->getStatus() || !$asset->isPublished()) {
            throw new InboxException('O canal desta conversa não está disponível para envio.', 409);
        }
        $body = $this->body($body);
        if ('facebook' === $state->getConversation()->getChannel() && mb_strlen($body) > 2000) { throw new InboxException('A resposta no Facebook pode ter no máximo 2.000 caracteres.'); }
        if ('instagram' === $state->getConversation()->getChannel() && mb_strlen($body) > 1000) { throw new InboxException('A resposta no Instagram pode ter no máximo 1.000 caracteres.'); }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestId)) {
            throw new InboxException('Identificador de envio inválido.');
        }
        $existing = $this->outboundRequests->findOneBy(['requestId' => $requestId]);
        if ($existing instanceof OutboundRequest) {
            if ($existing->getConversation()->getId() !== $state->getConversation()->getId() || $existing->getAuthor()->getId() !== $author->getId() || $existing->getBody() !== $body) {
                throw new InboxException('Identificador de envio já utilizado.', 409);
            }
            return $existing;
        }

        if (null !== ($reason = $this->replyAvailability->reason($state))) { throw new InboxException($reason, 409); }
        [$operation, $recipient] = $this->outboundTarget($state->getConversation());
        $job = $this->queue->enqueue($asset, $operation, [
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
        $this->log($state, $author, 'reply_queued', ['request_id' => $requestId]);

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

    private function body(string $body): string
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > 4000) {
            throw new InboxException('Escreva uma mensagem de até 4.000 caracteres.');
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
                throw new InboxException('Uma resposta privada já foi enviada ou solicitada. Aguarde a pessoa responder para continuar na conversa privada.', 409);
            }
            return ['instagram_private_reply', substr($conversation->getRecipient(), 8)];
        }
        $last = $this->messages->findOneBy(['conversation' => $conversation, 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC']);
        if ($last instanceof MetaMessage && 'comment' === $last->getMessageType()) {
            $commentId = trim((string) ($last->getPayload()['commentId'] ?? ''));
            if ('' === $commentId) {
                throw new InboxException('O comentário de origem não está disponível para resposta privada.', 409);
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
