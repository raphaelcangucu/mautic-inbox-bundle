<?php
declare(strict_types=1);
// Isolated doubles only: no autoloader, Mautic kernel or database connection.
namespace Mautic\UserBundle\Entity { class User { public function getId(): int { return 8; } } }
namespace MauticPlugin\MauticInboxBundle\Security { class ConversationAccess { public function assertView($state, $user): void {} } }
namespace MauticPlugin\MauticInboxBundle\Entity {
    class Job { public function __construct(private ?string $parent = null) {} public function getPayload(): array { return ['_retry_of' => $this->parent]; } }
    class ConversationState { public function getConversation(): int { return 289; } }
    class OutboundRequest {
        public function __construct(private int $id, private string $request, private string $status, private Job $job) {}
        public function getId(): int { return $this->id; }
        public function getRequestId(): string { return $this->request; }
        public function getStatus(): string { return $this->status; }
        public function getJob(): Job { return $this->job; }
    }
    class Query {
        public array $params = [];
        public function __construct(private array $rows) {}
        public function leftJoin(...$args): self { return $this; }
        public function addSelect(...$args): self { return $this; }
        public function where(...$args): self { return $this; }
        public function andWhere(...$args): self { return $this; }
        public function setParameter(string $key, $value): self { $this->params[$key] = $value; return $this; }
        public function orderBy(...$args): self { return $this; }
        public function getQuery(): self { return $this; }
        public function toIterable(): iterable {
            if ($this->params !== ['conversation' => 289, 'source' => 91]) throw new \RuntimeException('Retry scope changed');
            yield from $this->rows;
        }
    }
    class OutboundRequestRepository {
        public function __construct(private array $rows) {}
        public function findOneBy(array $criteria): ?OutboundRequest { return null; }
        public function createQueryBuilder(string $alias): Query { return new Query($this->rows); }
    }
}
namespace {
    use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
    use MauticPlugin\MauticInboxBundle\Application\InboxException;
    use MauticPlugin\MauticInboxBundle\Entity\{ConversationState, OutboundRequest, OutboundRequestRepository, Job};
    require __DIR__.'/../../Application/InboxException.php';
    require __DIR__.'/../../Application/ConversationActions.php';
    $reflection = new ReflectionClass(ConversationActions::class);
    $source = new OutboundRequest(91, 'original-request-001', 'failed', new Job());
    foreach (['sent', 'pending', 'failed'] as $status) {
        $child = new OutboundRequest(92, 'other-device-retry-001', $status, new Job($source->getRequestId()));
        $unrelated = new OutboundRequest(90, 'other-original-001', 'sent', new Job('unrelated-request-001'));
        $actions = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('access')->setValue($actions, new \MauticPlugin\MauticInboxBundle\Security\ConversationAccess());
        $reflection->getProperty('outboundRequests')->setValue($actions, new OutboundRequestRepository([$unrelated, $child]));
        // Queue and dispatcher are deliberately uninitialized: touching either fails.
        try {
            $result = $reflection->getMethod('retryLocked')->invoke($actions, new ConversationState(), $source, new \Mautic\UserBundle\Entity\User(), 'new-device-retry-001');
            if ($status === 'failed' || $result !== [$child, false]) throw new RuntimeException('Existing retry was not reused');
        } catch (InboxException $error) {
            if ($status !== 'failed' || $error->httpStatus !== 409 || $error->getMessage() !== 'mautic.inbox.ui.retry_superseded') throw $error;
        }
    }
    echo "3 stale retry guard cases passed; no queue dispatch, database or kernel.\n";
}
