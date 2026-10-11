<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Ai;

/** Server-owned authorization. A document/model response cannot enable an action. */
final class CommentAutomationPolicy
{
    public static function defaults(): array
    {
        return ['mode'=>'off', 'asset_ids'=>[], 'publication_ids'=>[], 'document_key'=>'', 'document_version'=>0,
            'mark_spam_enabled'=>false, 'public_reply_enabled'=>false, 'classify_limit'=>100, 'reply_limit'=>20,
            'min_interval'=>30, 'notify'=>true, 'cursor'=>0];
    }

    public static function normalize(array $input): array
    {
        $value = array_intersect_key($input, self::defaults()) + self::defaults();
        if (!in_array($value['mode'], ['off','review','automatic'], true)) throw new \DomainException('invalid_mode');
        $value['asset_ids'] = array_values(array_unique(array_map('intval', (array)$value['asset_ids'])));
        foreach((array)$value['publication_ids'] as $id) if (!is_string($id) || !preg_match('/^[0-9_]{5,80}$/D',$id)) throw new \DomainException('invalid_publication');
        $value['publication_ids'] = array_values(array_unique((array)$value['publication_ids']));
        foreach (['mark_spam_enabled','public_reply_enabled','notify'] as $key) $value[$key] = $value[$key] === true;
        foreach (['classify_limit'=>5000,'reply_limit'=>1000,'min_interval'=>3600] as $key=>$max) {
            if (!is_numeric($value[$key]) || (int)$value[$key] < ($key==='min_interval' ? 0 : 1) || (int)$value[$key]>$max) throw new \DomainException('invalid_limit');
            $value[$key] = (int)$value[$key];
        }
        $value['document_key'] = (string)$value['document_key'];
        $value['document_version'] = (int)$value['document_version'];
        unset($value['cursor']); // Only the server chooses the watermark.
        return $value;
    }

    public static function permission(array $config, array $global, array $agent, int $asset): bool
    {
        $permission = $asset.':comment';
        return ($config['mode']??'off')!=='off' && empty($config['paused']) && !empty($global['enabled']) && !empty($agent['enabled'])
            && ($agent['audience']??'customer')==='customer' && in_array($asset,$config['asset_ids']??[],true)
            && in_array($permission,$global['permissions']??[],true) && in_array($permission,$agent['permissions']??[],true);
    }

    public static function eligible(array $snapshot, array $config): bool
    {
        return in_array($snapshot['channel']??'', ['instagram','facebook'], true) && !empty($snapshot['comment_id'])
            && trim((string)($snapshot['body']??''))!==''
            && ($snapshot['recipient']??'')==='comment:'.$snapshot['comment_id'] && !empty($snapshot['publication_id'])
            && empty($snapshot['human']) && empty($snapshot['ai_assigned']) && empty($snapshot['deleted']) && empty($snapshot['own_comment'])
            && empty($snapshot['already_replied']) && empty($snapshot['spam']) && empty($snapshot['blocked'])
            && ($snapshot['lifecycle']??'')==='open' && !empty($snapshot['needs_response'])
            && (!$config['publication_ids'] || in_array($snapshot['publication_id'],$config['publication_ids'],true));
    }

    public static function key(int $asset, string $comment): string
    {
        return hash('sha256', $asset.':'.$comment);
    }

    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(array_intersect_key($snapshot,array_flip(['asset_id','comment_id','publication_id','body','deleted','context_versions'])), JSON_THROW_ON_ERROR));
    }

    public static function decision(array $result, array $snapshot): array
    {
        $review = ['decision'=>'review','reason'=>'unsafe_or_insufficient_context','text'=>''];
        $kind = $result['decision']??'';
        $confidence = $result['confidence']??null;
        if (!is_string($result['reason']??null) || !is_string($result['text']??null)) return $review;
        $reason = trim($result['reason']);
        if (!in_array($kind,['spam','legitimate','review'],true) || !is_numeric($confidence) || $confidence<0 || $confidence>1 || $reason==='' || mb_strlen($reason)>500 || ($result['safe']??false)!==true) return $review;
        if ($kind==='review') return ['decision'=>'review','reason'=>$reason,'text'=>''];
        if ($kind==='spam') {
            if (!is_string($result['evidence']??null)) return $review;
            $evidence = trim($result['evidence']);
            if ($confidence<0.9 || mb_strlen($evidence)<6 || !str_contains(mb_strtolower($snapshot['body']),mb_strtolower($evidence))) return $review;
            return ['decision'=>'spam','reason'=>$reason,'text'=>'','evidence'=>$evidence];
        }
        $text = trim((string)($result['text']??''));
        if ($confidence<0.75 || empty($snapshot['caption']) || $text==='' || mb_strlen($text)>900 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]|Bearer\s|\b(?:sk-|re_)[A-Za-z0-9_-]{12,}|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|\+\d[\d ()-]{8,}/iu',$text)) return $review;
        preg_match_all('~https?://[^\s<>]+~iu',$text,$links);
        foreach($links[0] as $link) if (!in_array(rtrim($link,'.!,;)'),$snapshot['allowed_links']??[],true)) return $review;
        return ['decision'=>'legitimate','reason'=>$reason,'text'=>$text];
    }

    public static function operation(string $channel): string
    {
        return match($channel) {'instagram'=>'instagram_public_reply','facebook'=>'facebook_public_reply',default=>throw new \DomainException('unsupported_comment_channel')};
    }
}
