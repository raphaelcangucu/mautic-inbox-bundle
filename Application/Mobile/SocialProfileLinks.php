<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

/** Public participant profiles, never page-scoped Messenger IDs or publication URLs. */
final class SocialProfileLinks
{
    public static function url(string $channel, mixed $value, bool $allowHandle = false): ?string
    {
        if (!is_string($value) || '' === ($value = trim($value))) return null;
        if ($allowHandle && !str_contains($value, '://')) {
            $handle = ltrim($value, '@');
            $pattern = $channel === 'instagram' ? '/^[a-zA-Z0-9._]{1,30}$/D' : '/^[a-zA-Z0-9.]{1,80}$/D';
            if (!preg_match($pattern, $handle) || ctype_digit($handle)) return null;
            $value = 'https://www.'.$channel.'.com/'.$handle.'/';
        }
        $u = parse_url($value);
        $hosts = $channel === 'instagram' ? ['instagram.com','www.instagram.com'] : ($channel === 'facebook' ? ['facebook.com','www.facebook.com','m.facebook.com'] : []);
        if (!is_array($u) || ($u['scheme']??'') !== 'https' || !in_array(strtolower($u['host']??''), $hosts, true) || isset($u['user']) || isset($u['pass']) || (isset($u['port']) && $u['port'] !== 443)) return null;
        $path = trim($u['path']??'', '/');
        if ($channel === 'facebook' && $path === 'profile.php') {
            parse_str($u['query']??'', $query);
            return is_string($query['id']??null) && preg_match('/^[1-9][0-9]{4,39}$/D', $query['id']) ? 'https://www.facebook.com/profile.php?id='.$query['id'] : null;
        }
        if ($channel === 'facebook' && preg_match('#^people/[a-zA-Z0-9._-]+/[1-9][0-9]{4,39}$#D', $path)) return 'https://www.facebook.com/'.$path.'/';
        $pattern = $channel === 'instagram' ? '/^[a-zA-Z0-9._]{1,30}$/D' : '/^[a-zA-Z0-9.]{1,80}$/D';
        if (!preg_match($pattern, $path) || ctype_digit($path) || in_array(strtolower($path), ['p','reel','reels','stories','explore','accounts','direct','about','privacy','terms','developer','developers','web','watch','groups','events','pages','marketplace','login','logout','dialog','sharer.php','l.php','settings','help','share','profile.php','photo.php','permalink.php','story.php','photos','videos','business','gaming','notifications','challenge','oauth','api'], true)) return null;
        return 'https://www.'.$channel.'.com/'.$path.'/';
    }

    public static function contact(object $contact): array
    {
        $links = [];
        foreach (['instagram','facebook'] as $channel) {
            $url = self::url($channel, $contact->getFieldValue($channel), true);
            if ($url !== null) $links[$channel] = $url;
        }
        return $links;
    }
}
