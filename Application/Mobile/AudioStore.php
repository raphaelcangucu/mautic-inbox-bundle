<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Private, scoped audio staging. No public URL, schema change or path supplied by the client. */
final class AudioStore
{
    private string $root;
    public const MAX_BYTES = 2097152;
    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir) { $this->root=$projectDir.'/var/inbox-mobile/audio'; }
    public function enabled(): bool { return is_file($this->root.'/enabled'); }
    public function store(int $state, int $user, int $asset, string $requestId, string $encoded): array
    {
        if ($state<1 || $user<1 || $asset<1 || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$requestId)) throw new \DomainException('invalid_audio');
        $bytes=self::decode($encoded); $hash=hash('sha256',$bytes);
        if (!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) throw new \RuntimeException('audio_storage_unavailable');
        $key=hash('sha256',$state.':'.$user.':'.$requestId); $lock=fopen($this->root.'/'.$key.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('audio_storage_unavailable');
        chmod($this->root.'/'.$key.'.lock',0600);
        try {
            $index=$this->root.'/'.$key.'.json';
            if (is_file($index)) { $existing=json_decode((string)file_get_contents($index),true,8,JSON_THROW_ON_ERROR); if (!hash_equals($existing['sha256'],$hash) || $existing['asset']!==$asset) throw new \DomainException('audio_conflict'); return ['audio_id'=>$existing['id'],'size'=>$existing['size'],'mime'=>'audio/mp4']; }
            $id=bin2hex(random_bytes(16));$record=['id'=>$id,'state'=>$state,'user'=>$user,'asset'=>$asset,'request_id'=>$requestId,'size'=>strlen($bytes),'sha256'=>$hash,'mime'=>'audio/mp4','created'=>gmdate(DATE_ATOM)];
            $file=$this->root.'/'.$id.'.m4a';if(file_put_contents($file,$bytes,LOCK_EX)!==strlen($bytes))throw new \RuntimeException('audio_storage_unavailable');chmod($file,0600);
            $json=json_encode($record,JSON_THROW_ON_ERROR);$meta=$this->root.'/'.$id.'.json';
            $this->writeMetadata($meta,$json);$this->writeMetadata($index,$json);
            return ['audio_id'=>$id,'size'=>strlen($bytes),'mime'=>'audio/mp4'];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function decode(string $encoded): string
    {
        if (strlen($encoded)>4*((self::MAX_BYTES+2)/3)+4) throw new \DomainException('audio_too_large');
        $bytes=base64_decode($encoded,true);
        // Only native MPEG-4 AAC containers, no playlists, remote URLs or executable content.
        if (!is_string($bytes) || strlen($bytes)<32 || strlen($bytes)>self::MAX_BYTES || substr($bytes,4,4)!=='ftyp' || !preg_match('/^[A-Za-z0-9 ]{4}$/D',substr($bytes,8,4))) throw new \DomainException('invalid_audio');
        self::validateContainer($bytes);
        return $bytes;
    }
    private function writeMetadata(string $path,string $json): void
    {
        $temporary=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json) || !chmod($temporary,0600) || !rename($temporary,$path))throw new \RuntimeException('audio_storage_unavailable');}
        finally {if(is_file($temporary))unlink($temporary);}
    }
    private static function boxes(string $bytes): array
    {
        $boxes=[];$offset=0;$length=strlen($bytes);
        while($offset<$length){
            if($length-$offset<8)throw new \DomainException('invalid_audio');
            $size=unpack('N',substr($bytes,$offset,4))[1];$type=substr($bytes,$offset+4,4);$header=8;
            if($size===1){if($length-$offset<16)throw new \DomainException('invalid_audio');$wide=unpack('Nhigh/Nlow',substr($bytes,$offset+8,8));if($wide['high']!==0)throw new \DomainException('invalid_audio');$size=$wide['low'];$header=16;}
            if($size===0)$size=$length-$offset;
            if($size<$header || $size>$length-$offset)throw new \DomainException('invalid_audio');
            $boxes[$type][]=substr($bytes,$offset+$header,$size-$header);$offset+=$size;
        }
        return $boxes;
    }
    private static function validateContainer(string $bytes): void
    {
        $top=self::boxes($bytes);
        if(count($top['moov']??[])!==1 || !array_filter($top['mdat']??[],fn(string $data)=>strlen($data)>0))throw new \DomainException('invalid_audio');
        $movie=self::boxes($top['moov'][0]);
        if(count($movie['trak']??[])!==1)throw new \DomainException('invalid_audio');
        $track=self::boxes($movie['trak'][0]);$media=self::boxes($track['mdia'][0]??'');
        $handler=$media['hdlr'][0]??'';
        if(strlen($handler)<12 || substr($handler,8,4)!=='soun')throw new \DomainException('invalid_audio');
        $header=$media['mdhd'][0]??'';$version=ord($header[0]??"\xff");
        if($version===0 && strlen($header)>=20){$scale=unpack('N',substr($header,12,4))[1];$duration=unpack('N',substr($header,16,4))[1];}
        elseif($version===1 && strlen($header)>=32){$scale=unpack('N',substr($header,20,4))[1];$wide=unpack('Nhigh/Nlow',substr($header,24,8));if($wide['high']!==0)throw new \DomainException('invalid_audio');$duration=$wide['low'];}
        else throw new \DomainException('invalid_audio');
        if($scale<1 || $duration<1 || $duration/$scale>181)throw new \DomainException('invalid_audio');
        $info=self::boxes($media['minf'][0]??'');$table=self::boxes($info['stbl'][0]??'');$description=$table['stsd'][0]??'';
        if(strlen($description)<16 || unpack('N',substr($description,4,4))[1]!==1)throw new \DomainException('invalid_audio');
        $entries=self::boxes(substr($description,8));
        if(count($entries['mp4a']??[])!==1)throw new \DomainException('invalid_audio');
    }
    public function record(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !is_file($this->root.'/'.$id.'.json'))throw new \DomainException('audio_not_found');
        $record=json_decode((string)file_get_contents($this->root.'/'.$id.'.json'),true,8,JSON_THROW_ON_ERROR);
        $file=$this->root.'/'.$id.'.m4a';if(!is_file($file)||filesize($file)!==$record['size'])throw new \DomainException('audio_not_found');
        $record['file']=$file;return $record;
    }
    public function forReply(string $id,int $state,int $user,string $requestId): array
    {
        $record=$this->record($id);
        if($record['state']!==$state || $record['user']!==$user || !hash_equals($record['request_id'],$requestId))throw new \DomainException('audio_scope_mismatch');
        return $record;
    }
}
