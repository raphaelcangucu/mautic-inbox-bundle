<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\UserBundle\Entity\User;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Entity\CannedResponse;
use MauticPlugin\MauticInboxBundle\Entity\CannedResponseRepository;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\CommentContext;
use MauticPlugin\MauticInboxBundle\Entity\DraftRepository;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Entity\Note;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\MetaEvents;

final class InboxQuery
{
    /** Os quatro tipos do historico, cada um com o desempate de ordem para quando o instante empata. */
    private const TIMELINE_TYPES = [
        MetaMessage::class => ['message', 4],
        OutboundRequest::class => ['outbound', 3],
        Note::class => ['note', 2],
        EventLog::class => ['event', 1],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private DraftRepository $drafts,
        private CannedResponseRepository $cannedResponses,
        private EventRepository $campaignEvents,
        private ConversationActions $actions,
        private CorePermissions $permissions,
        private MessagePresentation $presentation,
        private ReplyAvailability $replyAvailability,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'mautic.helper.twig.avatar')]
        private \Mautic\LeadBundle\Twig\Helper\AvatarHelper $avatars,
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string,counts:array<string,int>} */
    public function conversations(User $user, array $filters): array
    {
        $this->actions->wakeDue(100);
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 25)));
        $qb = $this->entityManager->createQueryBuilder()->select('s', 'c', 'a', 'contact', 'assignee')
            ->from(ConversationState::class, 's')->join('s.conversation', 'c')->join('c.asset', 'a')
            ->leftJoin('c.contact', 'contact')->leftJoin('s.assignee', 'assignee');
        $queue = (string) ($filters['queue'] ?? 'mine');
        if ('mine' === $queue) {
            $qb->andWhere('s.assignee = :currentUser')->setParameter('currentUser', $user);
        } elseif ('unassigned' === $queue) {
            $qb->andWhere('s.assignee IS NULL');
        } elseif ('all' !== $queue) {
            throw new InboxException('mautic.inbox.ui.invalid_queue_901af4');
        }
        $lifecycle = (string) ($filters['lifecycle'] ?? 'active');
        if ('active' === $lifecycle) {
            $qb->andWhere('s.lifecycle IN (:lifecycles)')->setParameter('lifecycles', ['open', 'snoozed']);
        } elseif (in_array($lifecycle, ['open', 'snoozed', 'resolved'], true)) {
            $qb->andWhere('s.lifecycle = :lifecycle')->setParameter('lifecycle', $lifecycle);
        } elseif ('all' !== $lifecycle) {
            throw new InboxException('mautic.inbox.ui.invalid_status_filter_71dd0e');
        }
        if (filter_var($filters['needs_response'] ?? false, FILTER_VALIDATE_BOOL)) {
            $qb->andWhere('s.needsResponse = :needs')->setParameter('needs', true);
        }
        $channel = trim((string) ($filters['channel'] ?? ''));
        if ('' !== $channel) {
            if (!in_array($channel, ['whatsapp', 'instagram', 'facebook'], true)) { throw new InboxException('mautic.inbox.ui.invalid_channel_df77fb'); }
            $qb->andWhere('c.channel = :channel')->setParameter('channel', $channel);
        }
        $kind = (string) ($filters['kind'] ?? 'private');
        if ('comments' === $kind) {
            $qb->andWhere('c.recipient LIKE :commentRecipient')->setParameter('commentRecipient', 'comment:%');
        } elseif ('private' === $kind) {
            $qb->andWhere('c.recipient NOT LIKE :commentRecipient')->setParameter('commentRecipient', 'comment:%');
        } else {
            throw new InboxException('mautic.inbox.ui.invalid_conversation_type_63145f');
        }
        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100);
        if ('' !== $search) {
            $qb->andWhere("LOWER(c.recipient) LIKE :search OR LOWER(a.name) LIKE :search OR LOWER(CONCAT(COALESCE(contact.firstname, ''), CONCAT(' ', COALESCE(contact.lastname, '')))) LIKE :search")
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        $cursor = $this->decodeCursor((string) ($filters['cursor'] ?? ''));
        if (null !== $cursor) {
            $qb->andWhere('(c.lastMessageAt < :cursorDate OR (c.lastMessageAt = :cursorDate AND s.id < :cursorId))')
                ->setParameter('cursorDate', $cursor[0])->setParameter('cursorId', $cursor[1]);
        }
        $states = $qb->orderBy('c.lastMessageAt', 'DESC')->addOrderBy('s.id', 'DESC')->setMaxResults($limit + 1)->getQuery()->getResult();
        $hasMore = count($states) > $limit;
        $states = array_slice($states, 0, $limit);
        $items = array_map(fn (ConversationState $state): array => $this->conversation($state), $states);
        $last = [] === $states ? null : $states[array_key_last($states)];

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $last instanceof ConversationState ? $this->encodeCursor($last->getConversation()->getLastMessageAt(), (int) $last->getId()) : null,
            'counts' => $this->queueCounts($user, $kind),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(ConversationState $state, User $user): array
    {
        $conversation = $state->getConversation();
        $contact = $conversation->getContact();
        $drafts = [];
        foreach ($this->drafts->findBy(['conversation' => $conversation, 'user' => $user]) as $draft) {
            $drafts[$draft->getMode()] = $draft->getBody();
        }

        $blockedReason = $this->replyAvailability->reason($state);
        $assignedToMe = $state->getAssignee()?->getId() === $user->getId();
        return $this->conversation($state) + [
            'contact' => null === $contact ? null : [
                'id' => $contact->getId(), 'name' => $contact->getName() ?: $this->translator->trans('mautic.inbox.ui.unnamed_contact_666b99'), 'email' => $contact->getEmail(),
                'phone' => $contact->getMobile() ?: $contact->getPhone(),
                'url' => '/s/contacts/view/'.$contact->getId(),
            ],
            'origins' => $this->origins($state),
            'drafts' => $drafts,
            'can_reply' => null === $blockedReason && $assignedToMe,
            'can_take_and_reply' => null === $blockedReason && null === $state->getAssignee(),
            'reply_blocked_reason' => $blockedReason,
            'reply_hint' => $blockedReason ?? (!$assignedToMe ? (null === $state->getAssignee() ? $this->translator->trans('mautic.inbox.ui.write_your_reply_sending_it_will_assign_this_conversation_to_you__52d1da') : $this->translator->trans('mautic.inbox.ui.conversation_assigned_to_name_transfer_it_to_yourself_before_repl_21d227', ['%name%' => $state->getAssignee()->getName()])) : $this->translator->trans('mautic.inbox.ui.your_reply_will_be_sent_by_account_3734ba', ['%account%' => $conversation->getAsset()->getName()])),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function timeline(ConversationState $state, ?string $before, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $cursor = $this->decodeTimeCursor($before);
        $conversation = $state->getConversation();
        $items = [];
        foreach (self::TIMELINE_TYPES as $class => [$kind, $rank]) {
            $qb = $this->entityManager->createQueryBuilder()->select('x')->from($class, 'x')->where('x.conversation = :conversation')->setParameter('conversation', $conversation);
            if (MetaMessage::class === $class) { $qb->andWhere('x.id NOT IN (SELECT humanJob.messageLogId FROM '.OutboundRequest::class.' humanRequest JOIN humanRequest.job humanJob WHERE humanRequest.conversation = :conversation AND humanJob.messageLogId IS NOT NULL)'); }
            if (null !== $cursor) {
                if ($rank < $cursor[1]) {
                    $qb->andWhere('x.dateAdded <= :before');
                } elseif ($rank > $cursor[1]) {
                    $qb->andWhere('x.dateAdded < :before');
                } else {
                    $qb->andWhere('(x.dateAdded < :before OR (x.dateAdded = :before AND x.id < :beforeId))')->setParameter('beforeId', $cursor[2]);
                }
                $qb->setParameter('before', $cursor[0]);
            }
            foreach ($qb->orderBy('x.dateAdded', 'DESC')->addOrderBy('x.id', 'DESC')->setMaxResults($limit + 1)->getQuery()->getResult() as $entity) {
                $items[] = $this->timelineItem($entity, $kind, $rank);
            }
        }
        usort($items, static fn (array $a, array $b): int => [$b['timestamp'], $b['rank'], $b['sort']] <=> [$a['timestamp'], $a['rank'], $a['sort']]);
        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $oldest = [] === $items ? null : $items[array_key_last($items)];
        $items = array_reverse($items);
        $items = array_map(fn (array $item): array => $this->withoutOrdering($item), $items);

        return ['items' => $items, 'next_cursor' => $hasMore && is_array($oldest) ? $this->encodeTimeCursor(new \DateTimeImmutable($oldest['timestamp']), $oldest['rank'], $oldest['sort']) : null];
    }

    /**
     * O item de historico de um envio recem-criado, na forma que o historico ja devolve. Quem
     * responde ao envio precisa disto para nao ter que recarregar o historico so para ver o que
     * acabou de escrever; montar o array em outro lugar criaria uma segunda forma, e as duas
     * divergem na primeira mudanca.
     *
     * @return array<string,mixed>
     */
    public function outboundItem(OutboundRequest $outbound): array
    {
        [$kind, $rank] = self::TIMELINE_TYPES[OutboundRequest::class];

        return $this->withoutOrdering($this->timelineItem($outbound, $kind, $rank));
    }

    /**
     * O resumo de uma conversa, na forma que a listagem ja devolve, para quem acabou de mexer nela
     * e teria que buscar a lista inteira para nao mostra-la velha.
     *
     * @return array<string,mixed>
     */
    public function summary(ConversationState $state): array
    {
        return $this->conversation($state);
    }

    /** @return list<array{id:int,name:string,body:string,enabled:bool}> */
    public function cannedResponses(): array
    {
        $enabledResponses = array_values(array_filter(
            $this->cannedResponses->findBy(['enabled' => true], ['name' => 'ASC'], 100),
            static fn (CannedResponse $response): bool => $response->isEnabled(),
        ));

        return array_map(static fn (CannedResponse $response): array => [
            'id' => (int) $response->getId(),
            'name' => $response->getName(),
            'body' => $response->getBody(),
            'enabled' => $response->isEnabled(),
        ], $enabledResponses);
    }

    /** @return list<array{id:int,name:string}> */
    public function channelNotices(): array
    {
        $notices = [];
        foreach ($this->entityManager->getRepository(\MauticPlugin\MauticMetaBundle\Entity\MetaAsset::class)->findBy(['type' => 'facebook_page', 'isPublished' => true]) as $page) {
            if (false === ($page->getSettings()['facebook_reply_enabled'] ?? true)) {
                $notices[] = $this->translator->trans('mautic.inbox.ui.account_incomplete_facebook_connection_authorize_reading_comment__7c9ca6', ['%account%' => $page->getName()]);
            }
        }
        return $notices;
    }

    public function users(): array
    {
        $users = $this->entityManager->getRepository(User::class)->findBy(['isPublished' => true], ['firstName' => 'ASC'], 200);
        $permission = $this->permissions->getPermissionObject('inbox');
        $metaPermission = $this->permissions->getPermissionObject('meta');
        $users = array_values(array_filter($users, static function (User $user) use ($permission, $metaPermission): bool {
            if ($user->isAdmin()) { return true; }
            return $permission->isGranted($user->getActivePermissions()['inbox'] ?? [], 'conversations', 'view')
                && $metaPermission->isGranted($user->getActivePermissions()['meta'] ?? [], 'messages', 'view');
        }));
        return array_map(static fn (User $user): array => ['id' => (int) $user->getId(), 'name' => $user->getName() ?: (string) $user->getUsername()], $users);
    }

    /** @return list<array<string,mixed>> */
    public function automationRules(): array
    {
        $rules = [];
        foreach ($this->campaignEvents->findBy(['type' => MetaEvents::CAMPAIGN_INSTAGRAM_COMMENT_TYPE], ['id' => 'DESC'], 100) as $event) {
            if (!$event instanceof Event || $event->isDeleted()) { continue; }
            $properties = $event->getProperties();
            $rules[] = [
                'id' => $event->getId(), 'name' => $event->getName(), 'campaign_id' => $event->getCampaign()->getId(),
                'campaign' => $event->getCampaign()->getName(), 'published' => $event->getCampaign()->isPublished(),
                'asset_id' => (int) ($properties['asset_id'] ?? 0), 'media_id' => (string) ($properties['media_id'] ?? ''),
                'keyword' => (string) ($properties['keyword'] ?? ''),
                'url' => '/s/campaigns/edit/'.$event->getCampaign()->getId(),
            ];
        }
        return $rules;
    }

    /** @return array{conversations:list<array<string,mixed>>,timeline:list<array<string,mixed>>,next_since:string,has_more:bool} */
    public function poll(User $user, string $since, ?ConversationState $selected, ?int $notificationCursor = null): array
    {
        try { $from = new \DateTimeImmutable($since); } catch (\Throwable) { throw new InboxException('mautic.inbox.ui.invalid_update_marker_cedfb7'); }
        if ($from < new \DateTimeImmutable('-24 hours')) { $from = new \DateTimeImmutable('-24 hours'); }
        $until = new \DateTimeImmutable();
        $states = $this->entityManager->createQueryBuilder()->select('s', 'c')->from(ConversationState::class, 's')->join('s.conversation', 'c')
            ->where('(s.dateModified > :from OR c.lastMessageAt > :from)')->andWhere('s.dateModified <= :until')->setParameter('from', $from)->setParameter('until', $until)
            ->orderBy('s.dateModified', 'ASC')->setMaxResults(51)->getQuery()->getResult();
        $hasMore = count($states) > 50;
        $states = array_slice($states, 0, 50);
        $timeline = [];
        if ($selected instanceof ConversationState) {
            foreach (self::TIMELINE_TYPES as $class => [$kind, $rank]) {
                $qb = $this->entityManager->createQueryBuilder()->select('x')->from($class, 'x')->where('x.conversation = :conversation')->andWhere('x.dateAdded > :from')->andWhere('x.dateAdded <= :until')
                    ->setParameter('conversation', $selected->getConversation())->setParameter('from', $from)->setParameter('until', $until);
            if (MetaMessage::class === $class) { $qb->andWhere('x.id NOT IN (SELECT humanJob.messageLogId FROM '.OutboundRequest::class.' humanRequest JOIN humanRequest.job humanJob WHERE humanRequest.conversation = :conversation AND humanJob.messageLogId IS NOT NULL)'); }
                foreach ($qb->orderBy('x.dateAdded', 'ASC')->setMaxResults(101)->getQuery()->getResult() as $entity) {
                        $timeline[] = $this->timelineItem($entity, $kind, $rank);
                }
            }
            usort($timeline, static fn (array $a, array $b): int => [$a['timestamp'], $a['rank'], $a['sort']] <=> [$b['timestamp'], $b['rank'], $b['sort']]);
            if (count($timeline) > 100) { $timeline = array_slice($timeline, 0, 100); $hasMore = true; }
            $timeline = array_map(fn (array $item): array => $this->withoutOrdering($item), $timeline);
        }
        $next = $hasMore && [] !== $states ? end($states)->getDateModified() : $until;

        $notifications = $this->notifications($notificationCursor);
        return ['conversations' => array_map(fn (ConversationState $state): array => $this->conversation($state), $states), 'timeline' => $timeline, 'next_since' => $next->format(DATE_ATOM), 'has_more' => $hasMore] + $notifications;
    }

    /** Incoming IDs are independent of UI filters, read state and historical message timestamps. */
    public function notifications(?int $cursor): array
    {
        $qb = $this->entityManager->createQueryBuilder()->from(MetaMessage::class, 'm')
            ->join(ConversationState::class, 'notificationState', 'WITH', 'notificationState.conversation = m.conversation')
            ->where("m.direction = 'inbound'");
        if (null === $cursor) {
            return ['notifications' => [], 'notification_cursor' => (int) $qb->select('MAX(m.id)')->getQuery()->getSingleScalarResult(), 'notifications_more' => false];
        }
        $rows = $qb->select('m.id AS id', 'notificationState.id AS state_id')->andWhere('m.id > :cursor')->setParameter('cursor', max(0, $cursor))
            ->orderBy('m.id', 'ASC')->setMaxResults(101)->getQuery()->getArrayResult();
        $more = count($rows) > 100;
        $rows = array_slice($rows, 0, 100);
        return ['notifications' => $rows, 'notification_cursor' => $rows ? (int) end($rows)['id'] : $cursor, 'notifications_more' => $more];
    }

    /** @return array<string,mixed> */
    private function conversation(ConversationState $state): array
    {
        $c = $state->getConversation();
        $contact = $c->getContact();
        $latest = $this->entityManager->getRepository(MetaMessage::class)->findOneBy(['conversation' => $c], ['dateAdded' => 'DESC', 'id' => 'DESC']);
        $preview = $latest instanceof MetaMessage ? mb_substr($this->timelineItem($latest, 'message', 4)['body'], 0, 180) : '';
        $inbound = $this->entityManager->getRepository(MetaMessage::class)->findOneBy(['conversation' => $c, 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC']);
        $identity = $inbound?->getPayload() ?? [];
        $participantName = $identity['contact']['profile']['name'] ?? $identity['commenterName'] ?? '';
        $participantName = is_string($participantName) ? $participantName : '';
        $profilePhoto = $identity['contact']['profile']['profile_pic'] ?? null;
        $photoHost = is_string($profilePhoto) ? parse_url($profilePhoto, PHP_URL_HOST) : null;
        $profilePhoto = is_string($photoHost) && str_starts_with($profilePhoto, 'https://') && (str_ends_with($photoHost, '.cdninstagram.com') || str_ends_with($photoHost, '.fbcdn.net') || str_ends_with($photoHost, '.fbsbx.com')) ? $profilePhoto : null;
        $public = str_starts_with($c->getRecipient(), 'comment:');
        $participant = $public ? (string) ($identity['commenterId'] ?? $this->translator->trans('mautic.inbox.ui.identity_unavailable_4301ba')) : $c->getRecipient();
        $handle = $identity['contact']['profile']['username'] ?? ('instagram' === $c->getChannel() ? ($identity['commenterName'] ?? '') : '');
        $handle = is_string($handle) && preg_match('/^@?[a-zA-Z0-9._]+$/', $handle) && !ctype_digit($handle) ? '@'.ltrim($handle, '@') : null;
        $displayName = $participantName ?: ($contact?->getName() ?: ($handle ?? ''));
        if ('' === $displayName || ctype_digit($displayName)) {
            $displayName = match ($c->getChannel()) {
                'instagram' => $this->translator->trans('mautic.inbox.ui.instagram_contact_aded57'),
                'facebook' => $this->translator->trans('mautic.inbox.ui.facebook_contact_959a01'),
                default => $participant,
            };
        }
        return [
            'contact_handle' => $handle,
            'avatar_url' => $profilePhoto ?: ($contact && ($contact->getEmail() || 'custom' === $contact->getPreferredProfileImage() || $contact->getSocialCache()) ? $this->avatars->getAvatar($contact) : null),
            'preview' => $preview,
            'id' => (int) $state->getId(), 'conversation_id' => (int) $c->getId(), 'version' => $state->getVersion(),
            'channel' => $c->getChannel(), 'asset' => ['id' => $c->getAsset()->getId(), 'name' => $c->getAsset()->getName(), 'handle' => $c->getAsset()->getUsername(), 'phone' => $c->getAsset()->getPhoneNumber()],
            'recipient' => $participant, 'contact_name' => $displayName,
            'conversation_kind' => $public ? ('reel' === ($identity['origin_media']['kind'] ?? null) ? $this->translator->trans('mautic.inbox.ui.reel_comment_6f7cab') : $this->translator->trans('mautic.inbox.ui.public_comment_4a1398')) : ('facebook' === $c->getChannel() ? 'Messenger' : $this->translator->trans('mautic.inbox.ui.private_message_e7efc2')),
            'reply_public' => $public && 'facebook' === $c->getChannel(),
            'assignee' => null === $state->getAssignee() ? null : ['id' => $state->getAssignee()->getId(), 'name' => $state->getAssignee()->getName()],
            'lifecycle' => $state->getLifecycle(), 'needs_response' => $state->needsResponse(), 'unread' => $c->getUnreadCount(),
            'human_takeover' => $state->isHumanTakeover(), 'snoozed_until' => $state->getSnoozedUntil()?->format(DATE_ATOM),
            'last_message_at' => $c->getLastMessageAt()->format(DATE_ATOM), 'updated_at' => $state->getDateModified()->format(DATE_ATOM),
        ];
    }

    /**
     * `rank` e `sort` decidem a ordem aqui dentro e nao significam nada para quem le a resposta.
     *
     * @return array<string,mixed>
     */
    private function withoutOrdering(array $item): array
    {
        unset($item['rank'], $item['sort']);

        return $item;
    }

    /** @return array<string,mixed> */
    private function timelineItem(object $entity, string $kind, int $rank): array
    {
        if ($entity instanceof MetaMessage) {
            $presented = $this->presentation->present($entity);
            $itemKind = 'comment' === $entity->getMessageType() ? 'comment' : ('outbound' === $entity->getDirection() ? 'automatic' : 'message');
            $job = 'outbound' === $entity->getDirection()
                ? $this->entityManager->getRepository(MetaOutboundJob::class)->findOneBy(['messageLogId' => $entity->getId()])
                : null;
            $jobPayload = $job instanceof MetaOutboundJob ? $job->getPayload() : [];
            $ai = 'inbox_ai' === ($jobPayload['_origin'] ?? null) ? [
                'agent' => trim((string) ($jobPayload['_ai_agent_name'] ?? '')) ?: 'AI',
                'key' => (string) ($jobPayload['_ai_agent_key'] ?? ''),
            ] : null;

            return $presented + ['kind' => $itemKind, 'id' => $entity->getId(), 'direction' => $entity->getDirection(), 'status' => $entity->getStatus(), 'timestamp' => $entity->getDateAdded()->format('Y-m-d\\TH:i:s.uP'), 'sort' => $entity->getId(), 'rank' => $rank, 'context' => 'comment' === $entity->getMessageType() ? ['linked' => true] : null, 'ai' => $ai];
        }
        if ($entity instanceof Note) {
            return ['kind' => 'note', 'id' => $entity->getId(), 'body' => $entity->getBody(), 'author' => $entity->getAuthor()->getName(), 'timestamp' => $entity->getDateAdded()->format('Y-m-d\\TH:i:s.uP'), 'sort' => $entity->getId(), 'rank' => $rank];
        }
        if ($entity instanceof OutboundRequest) {
            return ['kind' => 'outbound', 'id' => $entity->getId(), 'request_id' => $entity->getRequestId(), 'body' => $entity->getBody(), 'author' => $entity->getAuthor()->getName(), 'status' => $entity->getStatus(), 'retryable' => 'failed' === $entity->getStatus() && null !== $entity->getJob(), 'failure' => $entity->getFailureReason() && str_starts_with($entity->getFailureReason(), 'mautic.inbox.') ? $this->translator->trans($entity->getFailureReason()) : $entity->getFailureReason(), 'timestamp' => $entity->getDateAdded()->format('Y-m-d\\TH:i:s.uP'), 'sort' => $entity->getId(), 'rank' => $rank];
        }
        return ['kind' => 'event', 'id' => $entity->getId(), 'event' => $entity->getEventType(), 'author' => $entity->getActor()?->getName(), 'timestamp' => $entity->getDateAdded()->format('Y-m-d\\TH:i:s.uP'), 'sort' => $entity->getId(), 'rank' => $rank];
    }

    /** @return list<array<string,mixed>> */
    private function origins(ConversationState $state): array
    {
        $contexts = $this->entityManager->createQueryBuilder()->select('context')->from(CommentContext::class, 'context')
            ->where('context.publicConversation = :conversation OR context.privateConversation = :conversation')
            ->setParameter('conversation', $state->getConversation())->orderBy('context.dateAdded', 'DESC')->setMaxResults(10)->getQuery()->getResult();
        $items = [];
        foreach ($contexts as $context) {
            $url = $context->getPermalink();
            $parts = is_string($url) ? parse_url($url) : false;
            $url = is_array($parts) && 'https' === ($parts['scheme'] ?? '') && in_array($parts['host'] ?? '', ['instagram.com', 'www.instagram.com', 'facebook.com', 'www.facebook.com'], true) ? $url : null;
            $public = $context->getPublicConversation()->getId() === $state->getConversation()->getId();
            $related = $public ? $context->getPrivateConversation() : $context->getPublicConversation();
            $relatedState = null === $related ? null : $this->entityManager->getRepository(ConversationState::class)->findOneBy(['conversation' => $related]);
            $payload = $context->getMessage()->getPayload();
            $title = $this->translator->trans('mautic.inbox.ui.post_b172b7').$context->getMediaId();
            foreach ($this->automationRules() as $rule) { if ((string) $rule['media_id'] === $context->getMediaId() && (int) $rule['asset_id'] === $state->getConversation()->getAsset()->getId()) { $title = $rule['campaign']; break; } }
            $image = $payload['origin_media']['image'] ?? null;
            $host = is_string($image) ? parse_url($image, PHP_URL_HOST) : null;
            $image = is_string($host) && str_starts_with($image, 'https://') && (str_ends_with($host, '.cdninstagram.com') || str_ends_with($host, '.fbcdn.net')) ? $image : null;
            $items[] = ['image' => $image, 'caption' => $payload['origin_media']['caption'] ?? null, 'title' => $payload['origin_media']['caption'] ?? $title, 'media_id' => $context->getMediaId(), 'comment_id' => $context->getCommentId(), 'author' => $payload['commenterName'] ?? $context->getParticipantId(), 'body' => is_string($payload['text'] ?? null) ? mb_substr($payload['text'], 0, 500) : $this->translator->trans('mautic.inbox.ui.comment_on_the_post_4b4a80'), 'permalink' => $url, 'related_state_id' => $relatedState?->getId(), 'related_kind' => $public ? 'private' : 'comments'];
        }
        return $items;
    }

    /** @return array<string,int> */
    private function queueCounts(User $user, string $kind = 'private'): array
    {
        $count = function (?string $queue) use ($user, $kind): int {
            $qb = $this->entityManager->createQueryBuilder()->select('COUNT(s.id)')->from(ConversationState::class, 's')->where('s.lifecycle IN (:active)')->setParameter('active', ['open', 'snoozed']);
            $qb->join('s.conversation', 'c')->andWhere('c.recipient '.('comments' === $kind ? 'LIKE' : 'NOT LIKE').' :commentRecipient')->setParameter('commentRecipient', 'comment:%');
            if ('mine' === $queue) { $qb->andWhere('s.assignee = :user')->setParameter('user', $user); }
            if ('unassigned' === $queue) { $qb->andWhere('s.assignee IS NULL'); }
            return (int) $qb->getQuery()->getSingleScalarResult();
        };
        return ['mine' => $count('mine'), 'unassigned' => $count('unassigned'), 'all' => $count(null)];
    }

    private function encodeCursor(\DateTimeInterface $date, int $id): string { return rtrim(strtr(base64_encode($date->format('Y-m-d H:i:s.u').'|'.$id), '+/', '-_'), '='); }
    /** @return array{\DateTimeImmutable,int}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if ('' === $cursor) { return null; }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $raw || !preg_match('/^(.+)\|(\d+)$/', $raw, $m)) { throw new InboxException('mautic.inbox.ui.invalid_cursor_345197'); }
        try { return [new \DateTimeImmutable($m[1]), (int) $m[2]]; } catch (\Throwable) { throw new InboxException('mautic.inbox.ui.invalid_cursor_345197'); }
    }
    private function encodeTimeCursor(\DateTimeInterface $date, int $rank, int $id): string { return rtrim(strtr(base64_encode($date->format('Y-m-d H:i:s.uP').'|'.$rank.'|'.$id), '+/', '-_'), '='); }
    /** @return array{\DateTimeImmutable,int,int}|null */
    private function decodeTimeCursor(?string $cursor): ?array
    {
        if (null === $cursor || '' === $cursor) { return null; }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $raw || !preg_match('/^(.+)\|(\d+)\|(\d+)$/', $raw, $matches)) { throw new InboxException('mautic.inbox.ui.invalid_cursor_345197'); }
        try { return [new \DateTimeImmutable($matches[1]), (int) $matches[2], (int) $matches[3]]; } catch (\Throwable) { throw new InboxException('mautic.inbox.ui.invalid_cursor_345197'); }
    }

    /** @return list<int> */
    private function humanMessageIds(object $conversation): array
    {
        $ids = [];
        foreach ($this->entityManager->getRepository(OutboundRequest::class)->findBy(['conversation' => $conversation]) as $request) {
            $id = $request->getJob()?->getMessageLogId();
            if (null !== $id) { $ids[] = $id; }
        }
        return $ids;
    }
}
