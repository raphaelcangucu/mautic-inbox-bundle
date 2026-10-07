<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Lazy read-only Meta lookup. Lists only read cached metadata, never call Graph. */
final class PublicationContext
{
    private string $directory;
    public function __construct(private MetaGraphClientInterface $graph, private PageConnectionResolver $pages, #[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->directory=dirname(realpath($projectDir) ?: $projectDir).'/inbox-mobile-private/publications';
    }
    public static function link(mixed $url, string $channel): ?string
    {
        if (!is_string($url) || $url === '') { return null; } $parts=parse_url($url);
        $hosts=$channel === 'instagram' ? ['instagram.com','www.instagram.com'] : ['facebook.com','www.facebook.com','m.facebook.com'];
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && in_array(strtolower($parts['host'] ?? ''),$hosts,true) && ($parts['path'] ?? '/') !== '/' ? $url : null;
    }
    public static function image(mixed $url): ?string
    {
        if (!is_string($url)) { return null; } $parts=parse_url($url);$host=strtolower($parts['host'] ?? '');
        return ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && (str_ends_with($host,'.cdninstagram.com') || str_ends_with($host,'.fbcdn.net')) ? $url : null;
    }
    public function cached(int $asset, string $media): array
    {
        $path=$this->path($asset,$media);if(!is_file($path))return [];
        try{$data=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);return ($data['expires'] ?? 0)>time() ? $data['metadata'] : [];}catch(\Throwable){return [];}
    }
    public function resolve(ConversationState $state, array $origins, bool $force=false): array
    {
        if (!$origins) { return ['items'=>[],'available'=>false]; }
        $asset=$state->getConversation()->getAsset();$channel=$state->getConversation()->getChannel();$origin=$origins[0];$media=(string)($origin['media_id'] ?? '');
        if (!in_array($channel,['instagram','facebook'],true) || !preg_match('/^[0-9_]{5,80}$/D',$media)) { return ['items'=>$origins,'available'=>false]; }
        $cached=$force ? [] : $this->cached((int)$asset->getId(),$media);
        $available=true;
        if (!$cached) {
            try {
                $connection=$channel === 'facebook' ? $this->pages->resolve($asset) : $asset->getConnection();
                $fields=$channel === 'instagram' ? 'id,caption,permalink,media_type,media_url,thumbnail_url' : 'id,message,permalink_url,full_picture';
                $result=$this->graph->get($connection,$media,['fields'=>$fields]);
                $cached=['caption'=>mb_substr((string)($result['caption'] ?? $result['message'] ?? ''),0,5000),'permalink'=>self::link($result['permalink'] ?? $result['permalink_url'] ?? null,$channel),'image'=>self::image($result['thumbnail_url'] ?? (($result['media_type'] ?? '') === 'VIDEO' ? null : ($result['media_url'] ?? $result['full_picture'] ?? null)))];
                $this->store((int)$asset->getId(),$media,$cached);
            } catch (\Throwable) { $available=false; $cached=$this->cached((int)$asset->getId(),$media); }
        }
        $origins[0]=array_replace($origin,array_filter($cached,static fn($value)=>$value !== null && $value !== ''));
        return ['items'=>$origins,'available'=>$available];
    }
    /** Reversible Instagram hide, using the plugin's credential vault and rate limiter. */
    public function hideInstagram(ConversationState $state, array $origins, bool $hidden): void
    {
        $conversation=$state->getConversation();$asset=$conversation->getAsset();$origin=$origins[0] ?? [];
        $comment=(string)($origin['comment_id'] ?? '');$media=(string)($origin['media_id'] ?? '');
        if ($conversation->getChannel() !== 'instagram' || !$asset->isPublished() || $asset->getStatus() !== 'active' || !str_starts_with($conversation->getRecipient(),'comment:') || !preg_match('/^[0-9]{5,40}$/D',$comment) || !preg_match('/^[0-9]{5,40}$/D',$media)) {
            throw new \DomainException('unsupported_moderation');
        }
        $connection=$asset->getConnection();
        $owner=(new \MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver($this->graph))->resolve($asset);
        $remote=$this->graph->get($connection,$comment,['fields'=>'id,hidden,media,from']);
        $publication=$this->graph->get($connection,$media,['fields'=>'id,owner']);
        self::assertInstagramOwner($remote,$publication,$comment,$media,$owner);
        if (isset($remote['hidden']) && $remote['hidden'] === $hidden) { return; }
        $result=$this->graph->post($connection,$comment,['hide'=>$hidden]);
        if (($result['success'] ?? false) !== true) { throw new \DomainException('moderation_not_confirmed'); }
        $after=$this->graph->get($connection,$comment,['fields'=>'id,hidden']);
        if (($after['id'] ?? '') !== $comment || ($after['hidden'] ?? null) !== $hidden) { throw new \DomainException('moderation_not_confirmed'); }
    }
    public static function assertInstagramOwner(array $comment,array $media,string $commentId,string $mediaId,string $ownerId): void
    {
        if (($comment['id'] ?? '') !== $commentId || ($comment['media']['id'] ?? '') !== $mediaId || ($media['id'] ?? '') !== $mediaId || ($media['owner']['id'] ?? '') !== $ownerId || ($comment['from']['id'] ?? '') === $ownerId) {
            throw new \DomainException('moderation_scope_mismatch');
        }
    }
    private function path(int $asset,string $media): string { return $this->directory.'/'.hash('sha256',$asset.':'.$media).'.json'; }
    private function store(int $asset,string $media,array $metadata): void
    {
        $temporary=null;
        try { if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))return;
            $temporary=tempnam($this->directory,'.post-');if(!$temporary)return;chmod($temporary,0600);
            $json=json_encode(['expires'=>time()+900,'metadata'=>$metadata],JSON_THROW_ON_ERROR);
            if(file_put_contents($temporary,$json)===strlen($json))rename($temporary,$this->path($asset,$media));
        } catch (\Throwable) { /* Metadata remains usable if the optional cache cannot be written. */ } finally { if($temporary&&is_file($temporary))unlink($temporary); }
    }
}
