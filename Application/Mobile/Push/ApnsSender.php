<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile\Push;
use MauticPlugin\MauticInboxBundle\Application\Push\Ec;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Direct APNs HTTP/2. Provider keys never leave this server. */
final class ApnsSender
{
    private array $jwt = [];
    public function __construct(private ApnsConfiguration $configuration, private HttpClientInterface $http) {}
    public static function sign(array $config, int $now): string
    {
        $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $key = openssl_pkey_get_private($config['private_key']);
        if (!$key || (openssl_pkey_get_details($key)['ec']['curve_name'] ?? '') !== 'prime256v1') { throw new \RuntimeException('apns_key_invalid'); }
        $unsigned = $encode(json_encode(['alg'=>'ES256','kid'=>$config['key_id']], JSON_THROW_ON_ERROR)).'.'.$encode(json_encode(['iss'=>$config['team_id'],'iat'=>$now], JSON_THROW_ON_ERROR));
        if (!openssl_sign($unsigned, $der, $key, OPENSSL_ALGO_SHA256)) { throw new \RuntimeException('apns_sign_failed'); }
        return $unsigned.'.'.$encode(Ec::signatureToRaw($der));
    }
    public static function retryable(int $status): bool { return $status === 0 || $status === 429 || $status >= 500; }
    public function send(array $device, array $payload, string $collapse, int $expires): array
    {
        $config = $this->configuration->read($device['environment']);
        if (!$config || $config['bundle_id'] !== $device['bundle']) { return ['status'=>503,'reason'=>'NotConfigured']; }
        $identity = $device['environment'].':'.$config['key_id'].':'.hash('sha256', $config['private_key']);
        if (($this->jwt[$identity]['at'] ?? 0) < time()-3000) { $this->jwt[$identity] = ['at'=>time(),'token'=>$this->configuration->providerToken($identity,$config)]; }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($body) > 4096) { throw new \RuntimeException('apns_payload_too_large'); }
        $host = $device['environment'] === 'production' ? 'api.push.apple.com' : 'api.sandbox.push.apple.com';
        try {
            $response = $this->http->request('POST', 'https://'.$host.'/3/device/'.$device['token'], [
                'http_version'=>'2.0', 'timeout'=>10, 'max_duration'=>15, 'max_redirects'=>0,
                'headers'=>['authorization'=>'bearer '.$this->jwt[$identity]['token'], 'apns-topic'=>$device['bundle'], 'apns-push-type'=>'alert', 'apns-priority'=>'10', 'apns-expiration'=>(string)$expires, 'apns-collapse-id'=>$collapse, 'content-type'=>'application/json'], 'body'=>$body,
            ]);
            $status = $response->getStatusCode(); $raw = $response->getContent(false);
            $reason = $status === 200 ? 'Accepted' : (json_decode($raw, true)['reason'] ?? 'Rejected');
            return ['status'=>$status, 'reason'=>preg_replace('/[^a-zA-Z0-9]/', '', substr((string)$reason,0,80))];
        } catch (\Throwable) { return ['status'=>0, 'reason'=>'NetworkUnavailable']; }
    }
}
