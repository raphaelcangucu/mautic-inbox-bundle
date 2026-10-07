<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

/** Keep private QR attachments behind the native bearer boundary. */
final class MediaRoutes
{
    public static function items(array $items, string $origin): array
    {
        $base = parse_url($origin);
        foreach ($items as &$item) {
            foreach ($item['attachments'] ?? [] as $index => $attachment) {
                if (!is_string($attachment['url'] ?? null)) { continue; }
                $url = parse_url($attachment['url']);
                if (!is_array($url) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) { continue; }
                if (isset($url['host']) && (strtolower($url['host']) !== strtolower($base['host'] ?? '') || strtolower($url['scheme'] ?? '') !== strtolower($base['scheme'] ?? '') || ($url['port'] ?? null) !== ($base['port'] ?? null))) { continue; }
                if (preg_match('#^/s/whatsqr/media/([1-9][0-9]*)$#D', $url['path'] ?? '', $match)) {
                    $item['attachments'][$index]['url'] = '/s/inbox/api/media/'.$match[1];
                }
            }
        }
        unset($item);
        return $items;
    }
}
