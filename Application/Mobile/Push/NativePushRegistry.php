<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile\Push;

final class NativePushRegistry
{
    public function __construct(private PrivateStorage $storage, private ApnsConfiguration $configuration) {}
    public static function installation(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value)) { throw new \DomainException('invalid_installation'); }
        return $value;
    }
    public function register(array $grant, array $p): array
    {
        $installation = self::installation((string)($p['installation'] ?? ''));
        // Old mobile versions omit locale and retain their existing Portuguese notifications.
        $locale = NativePushLocale::checked(array_key_exists('locale',$p) ? $p['locale'] : 'pt-BR');
        if (!is_string($p['token'] ?? null) || !preg_match('/^[a-fA-F0-9]{64,200}$/D', $p['token']) || strlen($p['token']) % 2 !== 0 || !in_array($p['environment'] ?? '', ['development','production'], true) || !is_string($p['accountId'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $p['accountId']) || ($p['bundle'] ?? '') !== 'com.distributionmachine.mauticinbox.demo') { throw new \DomainException('invalid_device'); }
        if (!is_string($p['name'] ?? '') || (isset($p['openConversation']) && (!is_int($p['openConversation']) || $p['openConversation'] < 0)) || (isset($p['foreground']) && !is_bool($p['foreground']))) { throw new \DomainException('invalid_device'); }
        $prefs = $p['preferences'] ?? [];
        if (!is_array($prefs)) { throw new \DomainException('invalid_preferences'); }
        $safe = [];
        foreach (['enabled'=>true,'sound'=>true,'preview'=>false,'quiet'=>false,'grouped'=>true,'suppressOpen'=>true] as $key=>$default) { if (isset($prefs[$key]) && !is_bool($prefs[$key])) { throw new \DomainException('invalid_preferences'); } $safe[$key] = $prefs[$key] ?? $default; }
        $key = hash('sha256', $installation.':'.$grant['user']);
        $this->storage->transaction(function(array &$data) use ($key,$installation,$grant,$p,$safe,$locale): void {
            // One installation may keep several operators; identity and active session are explicit.
            $old = $data['devices'][$key] ?? [];
            $data['devices'][$key] = ['installation'=>$installation,'user'=>(int)$grant['user'],'session'=>$grant['session'],'accountId'=>$p['accountId'],'token'=>strtolower($p['token']),'environment'=>$p['environment'],'bundle'=>$p['bundle'],'name'=>mb_substr((string)($p['name'] ?? 'Mautic Inbox'),0,80),'locale'=>$locale,'preferences'=>$safe,'seen'=>time(),'open'=>(int)($p['openConversation'] ?? 0),'foreground'=>($p['foreground'] ?? false) === true,'retired'=>($old['retired'] ?? false) && ($old['token'] ?? '') === strtolower($p['token']) && ($old['environment'] ?? '') === $p['environment'],'tested'=>$old['tested'] ?? 0,'accepted'=>$old['accepted'] ?? null,'error'=>$old['error'] ?? null];
        });
        return $this->status($grant,$installation);
    }
    public function status(array $grant, string $installation): array
    {
        self::installation($installation);
        return $this->storage->transaction(function(array &$data) use ($grant,$installation): array {
            $d=$data['devices'][hash('sha256',$installation.':'.$grant['user'])] ?? null;
            if ($d && $d['session'] !== $grant['session']) { $d=null; }
            return ['transport'=>'apns','registered'=>$d !== null && !$d['retired'],'configured'=>$d ? $this->configuration->configured($d['environment']) : false,'environment'=>$d['environment'] ?? null,'enabled'=>$d['preferences']['enabled'] ?? false,'last_accepted_at'=>$d['accepted'] ?? null,'last_error'=>$d['error'] ?? null];
        });
    }
    public function remove(array $grant, string $installation): void
    {
        self::installation($installation);
        $this->storage->transaction(function(array &$data) use ($grant,$installation): void { $key=hash('sha256',$installation.':'.$grant['user']); if (($data['devices'][$key]['session'] ?? null) === $grant['session']) { unset($data['devices'][$key]); } });
    }
    public function enqueue(array $items): void
    {
        $this->storage->transaction(function(array &$data) use ($items): void {
            foreach ($data['done'] as $key=>$expires) { if ($expires < time()) { unset($data['done'][$key]); } }
            foreach ($data['jobs'] as $key=>$job) { if ($job['expires'] < time()) { unset($data['jobs'][$key]); } }
            foreach ($items as $item) {
                if (($item['messageId'] ?? 0) <= 0) { continue; }
                foreach ($data['devices'] as $key=>$device) {
                    if ($device['retired'] || !$device['preferences']['enabled'] || $device['seen'] < time()-2592000) { continue; }
                    $id=hash('sha256',$key.':'.$device['session'].':'.$item['messageId']);
                    if (isset($data['jobs'][$id]) || isset($data['done'][$id])) { continue; }
                    if (count($data['jobs']) >= 10000) { throw new \RuntimeException('push_queue_full'); }
                    $data['jobs'][$id]=['device'=>$key,'session'=>$device['session'],'token_hash'=>hash('sha256',$device['token']),'state'=>(int)$item['stateId'],'message'=>(int)$item['messageId'],'expires'=>time()+3600,'next'=>time(),'attempts'=>0];
                }
            }
        });
    }
    public function test(array $grant, string $installation): void
    {
        self::installation($installation);
        $this->storage->transaction(function(array &$data) use ($grant,$installation): void {
            $key=hash('sha256',$installation.':'.$grant['user']); $d=$data['devices'][$key] ?? null;
            if (!$d || $d['session'] !== $grant['session'] || $d['retired'] || !$this->configuration->configured($d['environment'])) { throw new \DomainException('push_not_ready'); }
            if (($d['tested'] ?? 0) > time()-30) { throw new \DomainException('push_test_rate_limited'); }
            $data['devices'][$key]['tested']=time();
            $data['jobs']['test-'.bin2hex(random_bytes(16))]=['device'=>$key,'session'=>$d['session'],'token_hash'=>hash('sha256',$d['token']),'state'=>0,'message'=>0,'expires'=>time()+300,'next'=>time(),'attempts'=>0];
        });
    }
}
