<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;

/** Explicit public/private routing, preserving existing callers' defaults. */
final class ReplyMode
{
    public static function resolve(string $channel, string $recipient, ?string $mode): string
    {
        $comment = str_starts_with($recipient, 'comment:');
        $mode ??= $comment && $channel === 'facebook' ? 'public' : 'private';
        if (!in_array($mode, ['public','private'], true)
            || ($mode === 'public' && (!$comment || !in_array($channel,['instagram','facebook'],true)))
            || ($mode === 'private' && $comment && $channel === 'facebook')) {
            throw new InboxException('Este canal não oferece esse modo de resposta.', 422);
        }
        return $mode;
    }
    public static function instagramPublic(string $channel, string $recipient, string $mode): bool
    {
        return $channel === 'instagram' && str_starts_with($recipient,'comment:') && $mode === 'public';
    }
}
