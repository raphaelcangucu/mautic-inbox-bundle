<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;
/** Public diagnostics are classified; raw provider responses never leave the server. */
final class OutboundFailure
{
    public static function describe(?string $error): array
    {
        if ($error && preg_match('/Local anti-spam cooldown active for this recipient \(([0-9]{1,5}) seconds\)/', $error, $match)) {
            return ['code' => 'local_cooldown', 'seconds' => min(86400, (int) $match[1])];
        }
        if ($error && str_contains($error, 'WhatsApp identity is linked to a different Mautic contact.')) {
            return ['code' => 'contact_identity_mismatch', 'seconds' => null];
        }
        return ['code' => $error ? 'delivery_failed' : null, 'seconds' => null];
    }
}
