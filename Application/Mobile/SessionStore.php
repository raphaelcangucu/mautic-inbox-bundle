<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Opaque, device-specific grants. Only token hashes are persisted, outside the public assets. */
final class SessionStore
{
    private string $directory;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->directory = $projectDir.'/var/inbox-mobile';
    }

    public function authorize(int $userId, string $fingerprint, string $challenge, string $redirect): string
    {
        return $this->transaction(function (array &$data) use ($userId, $fingerprint, $challenge, $redirect): string {
            $code = bin2hex(random_bytes(32));
            $data['codes'][hash('sha256', $code)] = ['user' => $userId, 'fingerprint' => $fingerprint, 'challenge' => $challenge, 'redirect' => $redirect, 'expires' => time() + 90];
            return $code;
        });
    }

    public function exchange(string $code, string $verifier, string $redirect): array
    {
        return $this->transaction(function (array &$data) use ($code, $verifier, $redirect): array {
            $key = hash('sha256', $code);
            $grant = $data['codes'][$key] ?? null;
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!$grant || $grant['expires'] < time() || !hash_equals($grant['redirect'], $redirect) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || !hash_equals($grant['challenge'], $challenge)) {
                throw new \DomainException('invalid_grant');
            }
            unset($data['codes'][$key]);
            return $this->issue($data, $grant);
        });
    }

    public function refresh(string $refresh): array
    {
        return $this->transaction(function (array &$data) use ($refresh): array {
            $key = hash('sha256', $refresh);
            $grant = $data['refresh'][$key] ?? null;
            if (!$grant || $grant['expires'] < time()) { throw new \DomainException('invalid_grant'); }
            unset($data['refresh'][$key], $data['access'][$grant['access']]);
            return $this->issue($data, $grant);
        });
    }

    public function startDevice(string $challenge, string $address): array
    {
        return $this->transaction(function (array &$data) use ($challenge, $address): array {
            $key = hash('sha256', $address); $rate = $data['rates'][$key] ?? ['count' => 0, 'expires' => time() + 3600];
            if ($rate['expires'] < time()) { $rate = ['count' => 0, 'expires' => time() + 3600]; }
            if ($rate['count'] >= 20) { throw new \DomainException('rate_limited'); }
            $rate['count']++; $data['rates'][$key] = $rate;
            $device = bin2hex(random_bytes(32));
            do { $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 8)); } while (isset($data['user_codes'][$code]));
            $hash = hash('sha256', $device); $data['user_codes'][$code] = $hash;
            $data['devices'][$hash] = ['challenge' => $challenge, 'expires' => time() + 600, 'user' => 0, 'user_code' => $code, 'last_poll' => 0];
            return ['device_code' => $device, 'user_code' => substr($code, 0, 4).'-'.substr($code, 4), 'expires_in' => 600, 'interval' => 5];
        });
    }

    public function approveDevice(string $code, int $user, string $fingerprint): void
    {
        $this->transaction(function (array &$data) use ($code, $user, $fingerprint): void {
            $code = strtoupper(str_replace('-', '', $code)); $hash = $data['user_codes'][$code] ?? '';
            $grant = $data['devices'][$hash] ?? null;
            if (!$grant || $grant['expires'] < time() || $grant['user'] !== 0) { throw new \DomainException('invalid_device_code'); }
            $data['devices'][$hash]['user'] = $user; $data['devices'][$hash]['fingerprint'] = $fingerprint;
        });
    }

    public function exchangeDevice(string $device, string $verifier): array
    {
        return $this->transaction(function (array &$data) use ($device, $verifier): array {
            $hash = hash('sha256', $device); $grant = $data['devices'][$hash] ?? null;
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!$grant || $grant['expires'] < time() || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || !hash_equals($grant['challenge'], $challenge)) { throw new \DomainException('invalid_grant'); }
            if ($grant['user'] === 0) { return ['error' => 'authorization_pending']; }
            unset($data['devices'][$hash], $data['user_codes'][$grant['user_code']]);
            return $this->issue($data, $grant);
        });
    }

    /** The email code is keyed by a secret held only by this requesting app. */
    public function startMagic(string $email, string $address, string $challenge, int $user, string $fingerprint): array
    {
        return $this->transaction(function (array &$data) use ($email, $address, $challenge, $user, $fingerprint): array {
            $now = time();
            $emailHash = hash('sha256', strtolower($email));
            $keys = ['email:'.$emailHash => 5, 'ip:'.hash('sha256', $address) => 20, 'global' => 1000];
            foreach ($keys as $key => $limit) {
                $rate = $data['magic_rates'][$key] ?? ['count' => 0, 'expires' => $now + 3600, 'last' => 0];
                if ($rate['expires'] < $now) { $rate = ['count' => 0, 'expires' => $now + 3600, 'last' => 0]; }
                if ($rate['count'] >= $limit || (str_starts_with($key, 'email:') && $rate['last'] > $now - 60)) { throw new \DomainException('rate_limited'); }
            }
            foreach ($keys as $key => $limit) {
                $rate = $data['magic_rates'][$key] ?? ['count' => 0, 'expires' => $now + 3600, 'last' => 0];
                if ($rate['expires'] < $now) { $rate = ['count' => 0, 'expires' => $now + 3600, 'last' => 0]; }
                $rate['count']++; $rate['last'] = $now; $data['magic_rates'][$key] = $rate;
            }
            // A resend from this app invalidates its previous code, without affecting another device.
            foreach ($data['magic'] as $key => $old) {
                if ($old['email_hash'] === $emailHash && $old['challenge'] === $challenge) { unset($data['magic'][$key]); }
            }
            $id = bin2hex(random_bytes(32)); $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $data['magic'][hash('sha256', $id)] = ['user' => $user, 'fingerprint' => $fingerprint, 'email_hash' => $emailHash, 'challenge' => $challenge, 'code_hash' => hash_hmac('sha256', $code, $id), 'expires' => $now + 300, 'attempts' => 0];
            return ['request_id' => $id, 'code' => $code, 'expires_in' => 300, 'resend_after' => 60];
        });
    }

    public function cancelMagic(string $id): void
    {
        $this->transaction(function (array &$data) use ($id): void { unset($data['magic'][hash('sha256', $id)]); });
    }

    public function exchangeMagic(string $id, string $code, string $verifier): array
    {
        // Return errors from the transaction so failed-attempt counters are committed.
        return $this->transaction(function (array &$data) use ($id, $code, $verifier): array {
            $key = hash('sha256', $id); $grant = $data['magic'][$key] ?? null;
            if (!$grant || $grant['expires'] <= time() || $grant['attempts'] >= 5) { return ['error' => 'invalid_grant']; }
            $data['magic'][$key]['attempts']++;
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!preg_match('/^[0-9]{6}$/D', $code) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || !hash_equals($grant['challenge'], $challenge) || !hash_equals($grant['code_hash'], hash_hmac('sha256', $code, $id)) || $grant['user'] < 1) {
                if ($data['magic'][$key]['attempts'] >= 5) { unset($data['magic'][$key]); }
                return ['error' => 'invalid_grant'];
            }
            unset($data['magic'][$key]);
            return $this->issue($data, $grant) + ['email_hash' => $grant['email_hash']];
        });
    }

    public function authenticate(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \DomainException('invalid_token'); }
        return $this->transaction(function (array &$data) use ($token): array {
            $grant = $data['access'][hash('sha256', $token)] ?? null;
            if (!$grant || $grant['expires'] < time()) { throw new \DomainException('invalid_token'); }
            return $grant;
        });
    }

    public function revoke(string $token): void
    {
        $this->transaction(function (array &$data) use ($token): void {
            $key = hash('sha256', $token);
            $grant = $data['access'][$key] ?? null;
            unset($data['access'][$key]);
            if ($grant) { unset($data['refresh'][$grant['refresh']]); }
        });
    }

    private function issue(array &$data, array $grant): array
    {
        $access = bin2hex(random_bytes(32)); $refresh = bin2hex(random_bytes(32));
        $a = hash('sha256', $access); $r = hash('sha256', $refresh);
        $base = ['user' => $grant['user'], 'fingerprint' => $grant['fingerprint'], 'session' => $grant['session'] ?? bin2hex(random_bytes(16))];
        $data['access'][$a] = $base + ['expires' => time() + 3600, 'refresh' => $r];
        $data['refresh'][$r] = $base + ['expires' => time() + 2592000, 'access' => $a];
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_in' => 3600, 'user_id' => $grant['user'], 'fingerprint' => $grant['fingerprint']];
    }

    private function transaction(callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { throw new \RuntimeException('mobile_storage_unavailable'); }
        $file = $this->directory.'/sessions.json';
        $handle = fopen($file, 'c+');
        if (!$handle) { throw new \RuntimeException('mobile_storage_unavailable'); }
        chmod($file, 0600);
        if (!flock($handle, LOCK_EX)) { fclose($handle); throw new \RuntimeException('mobile_storage_unavailable'); }
        try {
            $raw = stream_get_contents($handle);
            $data = '' === $raw ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $data += ['codes' => [], 'access' => [], 'refresh' => [], 'devices' => [], 'user_codes' => [], 'rates' => [], 'magic' => [], 'magic_rates' => []];
            foreach (['codes', 'access', 'refresh', 'devices', 'rates', 'magic', 'magic_rates'] as $kind) { foreach ($data[$kind] as $key => $grant) { if ($grant['expires'] < time()) { if ($kind === 'devices') { unset($data['user_codes'][$grant['user_code']]); } unset($data[$kind][$key]); } } }
            $result = $operation($data);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            rewind($handle); if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json)) { throw new \RuntimeException('mobile_storage_unavailable'); }
            fflush($handle);
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
