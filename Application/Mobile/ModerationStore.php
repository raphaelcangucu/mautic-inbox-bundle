<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Inbox-only moderation. The original social comment is always preserved. */
final class ModerationStore
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {}
    private function author(array $conversation): string { return hash('sha256', $conversation['channel'].':'.$conversation['asset']['id'].':'.$conversation['recipient']); }
    public function flags(array $conversation): array
    {
        return $this->transaction(function(array &$data) use ($conversation): array {
            return ['spam' => !empty($data['spam'][(string) $conversation['id']]), 'hidden' => false, 'blockedAuthor' => !empty($data['authors'][$this->author($conversation)])];
        });
    }
    public function apply(array $conversation, string $action, int $actor): void
    {
        if (!in_array($action, ['spam','restore','block','unblock'], true)) { throw new \DomainException('unsupported_moderation'); }
        $this->transaction(function(array &$data) use ($conversation,$action,$actor): void {
            $id = (string) $conversation['id']; $author = $this->author($conversation);
            if ($action === 'spam') { $data['spam'][$id] = true; }
            elseif ($action === 'restore') { unset($data['spam'][$id]); }
            elseif ($action === 'block') { $data['authors'][$author] = true; }
            else { unset($data['authors'][$author]); }
            $data['audit'][] = ['state_id' => (int) $id, 'actor' => $actor, 'action' => $action, 'at' => gmdate(DATE_ATOM)];
            $data['audit'] = array_slice($data['audit'], -5000);
        });
    }
    private function transaction(callable $operation): mixed
    {
        $dir = $this->projectDir.'/var/inbox-mobile';
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) { throw new \RuntimeException('mobile_storage_unavailable'); }
        $file = $dir.'/moderation.json'; $handle = fopen($file,'c+');
        if (!$handle) { throw new \RuntimeException('mobile_storage_unavailable'); }
        chmod($file,0600);
        if (!flock($handle,LOCK_EX)) { fclose($handle); throw new \RuntimeException('mobile_storage_unavailable'); }
        try {
            $raw=stream_get_contents($handle); $data=$raw === '' ? [] : json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            $data += ['spam'=>[],'authors'=>[],'audit'=>[]]; $result=$operation($data); $json=json_encode($data,JSON_THROW_ON_ERROR);
            rewind($handle); if(!ftruncate($handle,0)||fwrite($handle,$json)!==strlen($json)) { throw new \RuntimeException('mobile_storage_unavailable'); }
            fflush($handle); return $result;
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
}
