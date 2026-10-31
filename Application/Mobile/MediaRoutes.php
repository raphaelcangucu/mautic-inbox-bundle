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
            // O app renderiza o markdown do e-mail e abre o original pelo link assinado; o HTML
            // saneado so existe para o iframe do web e pesaria em toda pagina de historico.
            if (is_array($item['email'] ?? null)) { unset($item['email']['html']); }
            foreach ($item['attachments'] ?? [] as $index => $attachment) {
                if (!is_string($attachment['url'] ?? null)) { continue; }
                $url = parse_url($attachment['url']);
                if (!is_array($url) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) { continue; }
                if (isset($url['host']) && (strtolower($url['host']) !== strtolower($base['host'] ?? '') || strtolower($url['scheme'] ?? '') !== strtolower($base['scheme'] ?? '') || ($url['port'] ?? null) !== ($base['port'] ?? null))) { continue; }
                if (preg_match('#^/s/whatsqr/media/([1-9][0-9]*)$#D', $url['path'] ?? '', $match)) {
                    $item['attachments'][$index]['url'] = '/s/inbox/api/media/'.$match[1];
                } elseif (preg_match('#^(?:/[^/]+)*/s/inbox/api/email/([1-9][0-9]*)/attachments/([0-9]{1,4})$#D', $url['path'] ?? '', $match)) {
                    // Anexo de e-mail: o proxy autenticado por Bearer, no caminho que o transporte
                    // do app troca para api_base.
                    $item['attachments'][$index]['url'] = '/inbox/mobile/api/email/'.$match[1].'/attachments/'.$match[2];
                }
            }
        }
        unset($item);
        return $items;
    }
}
