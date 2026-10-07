<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile\Push;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Atomic, locked files outside the public root. No database schema is required. */
final class PrivateStorage
{
    public readonly string $directory;
    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->directory = dirname(realpath($projectDir) ?: $projectDir).'/inbox-mobile-private';
    }
    public function transaction(callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { throw new \RuntimeException('push_storage_unavailable'); }
        $lock = fopen($this->directory.'/native-push.lock', 'c');
        if (!$lock) { throw new \RuntimeException('push_storage_unavailable'); }
        chmod($this->directory.'/native-push.lock', 0600);
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new \RuntimeException('push_storage_unavailable'); }
        $temporary = null;
        try {
            $file = $this->directory.'/native-push.json';
            $data = is_file($file) ? json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [];
            $data += ['devices'=>[], 'jobs'=>[], 'done'=>[]];
            $result = $operation($data);
            $temporary = tempnam($this->directory, '.push-');
            if (!$temporary) { throw new \RuntimeException('push_storage_unavailable'); }
            chmod($temporary, 0600);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) { throw new \RuntimeException('push_storage_unavailable'); }
            return $result;
        } finally { if ($temporary && is_file($temporary)) { unlink($temporary); } flock($lock, LOCK_UN); fclose($lock); }
    }
}
