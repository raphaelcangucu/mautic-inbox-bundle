<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile\Push;

final class ApnsConfiguration
{
    public function __construct(private PrivateStorage $storage) {}
    public function read(string $environment): ?array
    {
        if (!in_array($environment, ['development','production'], true)) { return null; }
        $path = $this->storage->directory.'/apns.json';
        if (!is_file($path)) { return null; }
        if ((fileperms($path) & 0077) !== 0) { throw new \RuntimeException('apns_config_permissions'); }
        $all = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $config = $all[$environment] ?? null;
        if (!$config) { return null; }
        foreach (['team_id','key_id'] as $field) { if (!is_string($config[$field] ?? null) || !preg_match('/^[A-Z0-9]{10}$/D', $config[$field])) { throw new \RuntimeException('apns_config_invalid'); } }
        if (!is_string($config['bundle_id'] ?? null) || !preg_match('/^[a-zA-Z0-9.-]{3,200}$/D', $config['bundle_id']) || !preg_match('/^AuthKey_[A-Z0-9]{10}\.p8$/D', $config['key_file'] ?? '')) { throw new \RuntimeException('apns_config_invalid'); }
        $keyPath = $this->storage->directory.'/'.$config['key_file'];
        if (!is_file($keyPath) || is_link($keyPath) || (fileperms($keyPath) & 0077) !== 0) { throw new \RuntimeException('apns_key_unavailable'); }
        $config['private_key'] = file_get_contents($keyPath);
        return $config;
    }
    /** Share provider JWTs across short-lived cron workers; APNs limits token updates. */
    public function providerToken(string $identity, array $config): string
    {
        return $this->storage->transaction(static function(array &$data) use ($identity,$config): string {
            $data['providers'] ??= [];
            foreach ($data['providers'] as $key=>$cached) { if ($cached['at'] < time()-3000 || $cached['at'] > time()) { unset($data['providers'][$key]); } }
            if (!isset($data['providers'][$identity])) { $data['providers'][$identity]=['at'=>time(),'token'=>ApnsSender::sign($config,time())]; }
            return $data['providers'][$identity]['token'];
        });
    }
    public function configured(string $environment): bool { try { $config=$this->read($environment); if (!$config) { return false; } $key=openssl_pkey_get_private($config['private_key']); return $key && (openssl_pkey_get_details($key)['ec']['curve_name'] ?? '') === 'prime256v1'; } catch (\Throwable) { return false; } }
}
