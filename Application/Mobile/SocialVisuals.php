<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Lists read only the cache. Visible mobile rows opt in to bounded Graph lookups. */
final class SocialVisuals
{
    private string $directory;
    public function __construct(private MetaGraphClientInterface $graph, private PageConnectionResolver $pages, private PublicationContext $publications, #[Autowire('%kernel.project_dir%')] string $projectDir)
    { $this->directory=dirname(realpath($projectDir) ?: $projectDir).'/inbox-mobile-private/social-visuals'; }

    public function cached(ConversationState $state, array $raw): array
    {
        if (!in_array($state->getConversation()->getChannel(),['instagram','facebook'],true)) return [];
        $asset=(int)$state->getConversation()->getAsset()->getId();
        return ['avatar_url'=>PublicationContext::image($this->read('author:'.$asset.':'.($raw['recipient']??''))['url']??null), 'asset_avatar_url'=>PublicationContext::image($this->read('asset:'.$asset)['url']??null), 'publication'=>$this->read('post:'.$asset.':'.($raw['origins'][0]['media_id']??''))['publication']??null];
    }

    public function resolve(ConversationState $state, array $raw): array
    {
        $conversation=$state->getConversation(); $channel=$conversation->getChannel();
        if (!in_array($channel,['instagram','facebook'],true)) return ['available'=>false];
        $asset=$conversation->getAsset(); $assetId=(int)$asset->getId();
        $connection=$channel==='facebook' ? $this->pages->resolve($asset) : $asset->getConnection();
        $this->remember('asset:'.$assetId,function() use($asset,$channel,$connection):array {
            $id=$channel==='instagram' ? (new InstagramAccountResolver($this->graph))->resolve($asset) : $asset->getExternalId();
            $profile=$this->graph->get($connection,$id,['fields'=>$channel==='instagram'?'id,profile_picture_url':'id,picture.type(normal)']);
            return ['url'=>PublicationContext::image($profile['profile_picture_url']??$profile['picture']['data']['url']??null)];
        });
        $participant=(string)($raw['recipient']??''); $origin=$raw['origins'][0]??[];
        if (preg_match('/^[0-9]{5,40}$/D',$participant)) {
            $this->remember('author:'.$assetId.':'.$participant,function() use($channel,$connection,$participant,$origin):array {
                if ($channel==='facebook' && preg_match('/^[0-9_]{5,80}$/D',(string)($origin['comment_id']??''))) {
                    $comment=$this->graph->get($connection,$origin['comment_id'],['fields'=>'id,from{id,picture.type(normal)}']);
                    if (($comment['from']['id']??'')!==$participant) return ['url'=>null];
                    return ['url'=>PublicationContext::image($comment['from']['picture']['data']['url']??null)];
                }
                $profile=$this->graph->get($connection,$participant,['fields'=>$channel==='instagram'?'id,profile_pic':'id,picture.type(normal)']);
                if (($profile['id']??'')!==$participant) return ['url'=>null];
                return ['url'=>PublicationContext::image($profile['profile_pic']??$profile['picture']['data']['url']??null)];
            });
        }
        if (!empty($origin['media_id'])) $this->remember('post:'.$assetId.':'.$origin['media_id'],fn()=>['publication'=>$this->publications->resolve($state,$raw['origins']??[])['items'][0]??null]);
        return ['available'=>true]+$this->cached($state,$raw);
    }
    private function path(string $key):string { return $this->directory.'/'.hash('sha256',$key); }
    private function read(string $key):array
    {
        $path=$this->path($key).'.json'; if(!is_file($path)) return [];
        try { $data=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR); return ($data['expires']??0)>time() && is_array($data['value']??null) ? $data['value'] : []; } catch(\Throwable) { return []; }
    }
    private function remember(string $key, callable $fetch):array
    {
        if ($cached=$this->read($key)) return $cached;
        if(!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) return [];
        $lock=fopen($this->path($key).'.lock','c'); if(!$lock)return []; chmod($this->path($key).'.lock',0600);
        $temporary=null;
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB))return [];
            if($cached=$this->read($key))return $cached;
            try { $value=$fetch(); $ttl=900; } catch(\Throwable) { $value=['url'=>null,'unavailable'=>true]; $ttl=300; }
            $temporary=tempnam($this->directory,'.visual-'); if(!$temporary)return $value; chmod($temporary,0600);
            $encoded=json_encode(['expires'=>time()+$ttl,'value'=>$value],JSON_THROW_ON_ERROR);
            if(file_put_contents($temporary,$encoded)===strlen($encoded))rename($temporary,$this->path($key).'.json');
            return $value;
        } finally { if($temporary&&is_file($temporary))unlink($temporary); flock($lock,LOCK_UN); fclose($lock); }
    }
}
