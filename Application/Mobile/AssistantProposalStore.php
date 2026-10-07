<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use MauticPlugin\MauticInboxBundle\Application\InboxException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Opaque, actor-bound proposals. No client-supplied action is executable. */
final class AssistantProposalStore
{
    private string $directory;
    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->directory=dirname(realpath($projectDir)?:$projectDir).'/inbox-mobile-private/assistant-actions';
    }
    public function create(int $actor,string $agent,array $action,array $preview): array
    {
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new InboxException('mautic.inbox.ai.pi_failed',503);
        chmod($this->directory,0700);
        $id=bin2hex(random_bytes(24));$expires=time()+600;
        $record=['actor'=>$actor,'agent'=>$agent,'action'=>$action,'preview'=>$preview,'expires'=>$expires,'state'=>'pending'];
        $file=fopen($this->directory.'/'.$id.'.json','x');if(!$file)throw new InboxException('mautic.inbox.ai.pi_failed',503);
        try{chmod($this->directory.'/'.$id.'.json',0600);$json=json_encode($record,JSON_THROW_ON_ERROR);if(fwrite($file,$json)!==strlen($json))throw new \RuntimeException('proposal_storage_failed');fflush($file);}finally{fclose($file);}
        return ['id'=>$id,'expires_at'=>gmdate(DATE_ATOM,$expires),'agent_key'=>$agent]+$preview;
    }
    public function execute(string $id,int $actor,string $agent,callable $authorize,callable $execute): array
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$id))throw new InboxException('Ação não encontrada.',404);
        $path=$this->directory.'/'.$id.'.json';if(!is_file($path)||is_link($path))throw new InboxException('Ação não encontrada.',404);
        $file=fopen($path,'r+');if(!$file)throw new InboxException('Ação não encontrada.',404);
        try{
            if(!flock($file,LOCK_EX|LOCK_NB))throw new InboxException('A ação já está sendo processada.',409);
            $record=json_decode(stream_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            if(($record['actor']??0)!==$actor||($record['agent']??null)!==$agent)throw new InboxException('Ação não encontrada.',404);
            // Permission revocation applies even to a replay of a completed action.
            $authorize($record['action']);
            if(($record['state']??'')==='completed')return $record['result'];
            if(($record['expires']??0)<=time())throw new InboxException('A revisão expirou. Peça uma nova proposta.',409);
            if(($record['state']??'')!=='pending')throw new InboxException('Confira o resultado no atendimento antes de tentar novamente.',409);
            $record['state']='executing';$this->save($file,$record);
            try{$result=$execute($record['action'],'assistant_'.$id);$record['state']='completed';$record['result']=$result;$this->save($file,$record);return $result;}
            catch(\Throwable $e){$record['state']='failed';$this->save($file,$record);throw $e;}
        }finally{flock($file,LOCK_UN);fclose($file);}
    }
    /** Read an executed result only; this endpoint can never execute a proposal. */
    public function result(string $id,int $actor,string $agent,callable $authorize): array
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$id))throw new InboxException('Ação não encontrada.',404);
        $path=$this->directory.'/'.$id.'.json';if(!is_file($path)||is_link($path))throw new InboxException('Ação não encontrada.',404);
        $file=fopen($path,'r');if(!$file)throw new InboxException('Ação não encontrada.',404);
        try{
            if(!flock($file,LOCK_SH|LOCK_NB))throw new InboxException('A ação está sendo processada.',409);
            $record=json_decode(stream_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            if(($record['actor']??0)!==$actor||($record['agent']??null)!==$agent)throw new InboxException('Ação não encontrada.',404);
            $authorize($record['action']);
            if(($record['state']??'')!=='completed')throw new InboxException('A ação ainda não tem resultado confirmado.',409);
            return $record['result'];
        }finally{flock($file,LOCK_UN);fclose($file);}
    }
    private function save($file,array $record): void
    {
        $json=json_encode($record,JSON_THROW_ON_ERROR);rewind($file);if(!ftruncate($file,0)||fwrite($file,$json)!==strlen($json))throw new \RuntimeException('proposal_storage_failed');fflush($file);
    }
}
