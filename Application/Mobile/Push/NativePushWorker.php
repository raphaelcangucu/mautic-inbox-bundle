<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile\Push;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Mobile\{SessionStore,ModerationStore};
use MauticPlugin\MauticInboxBundle\Application\Push\InboxAccess;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;

final class NativePushWorker
{
    public function __construct(private PrivateStorage $storage, private ApnsSender $sender, private SessionStore $sessions, private EntityManagerInterface $em, private \Mautic\CoreBundle\Security\Permissions\CorePermissions $permissions, private ConversationStateRepository $states, private InboxQuery $query, private ModerationStore $moderation, private \MauticPlugin\MauticInboxBundle\Security\ConversationAccess $access) {}
    public function run(int $limit): array
    {
        $snapshot=$this->storage->transaction(static fn(array &$data): array => $data);
        $counts=['accepted'=>0,'skipped'=>0,'retry'=>0,'failed'=>0]; $processed=0;
        foreach ($snapshot['jobs'] as $id=>$job) {
            if ($processed >= $limit) { break; } if ($job['next'] > time()) { continue; } ++$processed;
            // Re-read before delivery so preference changes and logout take effect between jobs.
            $device=$this->storage->transaction(static fn(array &$data): ?array => $data['devices'][$job['device']] ?? null);
            $grant=$device ? $this->sessions->activeSession($device['session']) : null;
            $user=$grant ? $this->em->find(User::class,$grant['user']) : null;
            if ($user instanceof User) $this->em->refresh($user);
            if (!$device || $job['expires'] <= time() || $device['session'] !== $job['session'] || !hash_equals($job['token_hash'],hash('sha256',$device['token'])) || $device['retired'] || !$device['preferences']['enabled'] || $device['preferences']['quiet'] || !$user instanceof User || !$user->isPublished() || !hash_equals($grant['fingerprint'],hash('sha256',(string)$user->getPassword())) || !$this->canView($user)) { $this->finish($id,$job,null,null); ++$counts['skipped']; continue; }
            $state=$job['state'] ? $this->states->find($job['state']) : null;
            if ($state) { $this->em->refresh($state); $this->em->refresh($state->getConversation()); }
            if ($state && !$this->access->canView($state,$user)) { $this->finish($id,$job,null,null); ++$counts['skipped']; continue; }
            $raw=$state ? $this->query->summary($state,$user) : null;
            if ($job['state']) {
                $flags=$raw ? $this->moderation->flags($raw) : [];
                $conversation = $state ? [
                    'lifecycle' => $state->getLifecycle(),
                    'assigneeId' => $state->getAssignee()?->getId(),
                    'lastInboundId' => $state->getLastInboundMessageId(),
                    'unread' => $raw['unread'] ?? 0,
                    'spam' => !empty($flags['spam']),
                    'blockedAuthor' => !empty($flags['blockedAuthor']),
                ] : [];
                if (!self::conversationEligible($device, $job, $conversation, (int) $user->getId(), time())) { $this->finish($id,$job,null,null); ++$counts['skipped']; continue; }
            }
            $payload=self::payload($device,$raw,$job['state']);
            $collapse=hash('sha256',$device['accountId'].':'.($device['preferences']['grouped'] ? $job['state'] : $id));
            $result=$this->sender->send($device,$payload,$collapse,$job['expires']);
            $retry=ApnsSender::retryable($result['status']) && $job['attempts'] < 6;
            $this->finish($id,$job,$result,$retry);
            ++$counts[$result['status'] === 200 ? 'accepted' : ($retry ? 'retry' : 'failed')];
        }
        $this->em->clear();
        return $counts;
    }
    private function canView(User $user): bool
    {
        return $this->access->canViewInbox($user);
    }
    /** Recheck the current conversation when a queued alert is about to leave. */
    public static function conversationEligible(array $device, array $job, array $conversation, int $operatorId, int $now): bool
    {
        if (($conversation['lifecycle'] ?? null) !== 'open' || ($conversation['unread'] ?? 0) <= 0 || !empty($conversation['spam']) || !empty($conversation['blockedAuthor'])) {
            return false;
        }
        if (isset($conversation['assigneeId']) && (int) $conversation['assigneeId'] !== $operatorId) {
            return false;
        }
        if ($device['preferences']['grouped'] && ($conversation['lastInboundId'] ?? null) !== $job['message']) {
            return false;
        }
        return !($device['preferences']['suppressOpen'] && $device['foreground'] && $device['seen'] > $now - 60 && $device['open'] === $job['state']);
    }
    public static function payload(array $device, ?array $raw, int $state): array
    {
        // Generic by default: customer names and message contents are not sent to Apple.
        $title=$device['name']; $body=$state ? 'Você recebeu uma nova mensagem.' : 'Push remoto do Mautic conectado a este aparelho.';
        if ($raw && $device['preferences']['preview']) { $title.=' · '.mb_substr($raw['contact_name'] ?? 'Contato',0,80); $body=mb_substr($raw['preview'] ?? 'Nova mensagem',0,500); }
        $aps=['alert'=>['title'=>$title,'body'=>$body]];
        if ($device['preferences']['sound']) { $aps['sound']='default'; }
        if ($device['preferences']['grouped']) { $aps['thread-id']='inbox-'.$device['accountId'].'-'.$state; }
        return ['aps'=>$aps,'accountId'=>$device['accountId'],'conversationId'=>$state,'type'=>$state ? 'inbound_message' : 'push_test'];
    }
    private function finish(string $id, array $job, ?array $result, ?bool $retry): void
    {
        $this->storage->transaction(function(array &$data) use ($id,$job,$result,$retry): void {
            if (!isset($data['jobs'][$id])) { return; }
            if ($retry) { $data['jobs'][$id]['attempts']++; $data['jobs'][$id]['next']=time()+min(900,15*(2**$data['jobs'][$id]['attempts']))+random_int(0,5); }
            else { unset($data['jobs'][$id]); $data['done'][$id]=time()+86400; }
            $key=$job['device'];
            if ($result && isset($data['devices'][$key]) && hash_equals($job['token_hash'],hash('sha256',$data['devices'][$key]['token']))) {
                $data['devices'][$key]['error']=$result['status'] === 200 ? null : $result['reason'];
                if ($result['status'] === 200) { $data['devices'][$key]['accepted']=gmdate(DATE_ATOM); }
                if ($result['status'] === 410 || ($result['status'] === 400 && in_array($result['reason'],['BadDeviceToken','DeviceTokenNotForTopic'],true))) { $data['devices'][$key]['retired']=true; }
            }
        });
    }
}
