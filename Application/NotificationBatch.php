<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

/** Skip imported history while advancing the cursor across every scanned ID. */
final class NotificationBatch
{
    public static function present(array $rows, int $cursor): array
    {
        $more = count($rows) > 100;
        $scanned = array_slice($rows, 0, 100);
        $next = $scanned ? (int) end($scanned)['id'] : $cursor;
        $notifications = [];
        foreach ($scanned as $row) {
            $payload = is_array($row['notification_payload'] ?? null) ? $row['notification_payload'] : [];
            $historical = true === ($payload['whatsqr']['historical'] ?? $payload['message']['historical'] ?? false);
            // Resposta automatica e devolucao de e-mail entram na conversa sem chamar ninguem.
            $automatic = is_array($payload['email'] ?? null) && (!empty($payload['email']['auto_reply']) || in_array($payload['email']['kind'] ?? null, ['auto_reply', 'bounce'], true));
            unset($row['notification_payload']);
            if (!$historical && !$automatic) { $notifications[] = $row; }
        }
        return ['notifications' => $notifications, 'notification_cursor' => $next, 'notifications_more' => $more];
    }
}
