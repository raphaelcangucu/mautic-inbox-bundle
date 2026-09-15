<?php
namespace MauticPlugin\MauticInboxBundle\Application\Ai;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\AiRecord;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
final class AiStore {
    public function __construct(private EntityManagerInterface $em){}
    public function find(string $kind,string $key): ?AiRecord {$r=$this->em->getRepository(AiRecord::class)->findOneBy(['kind'=>$kind,'recordKey'=>$key]);if($r)$this->em->refresh($r);return $r;}
    public function get(string $kind,string $key): array {return $this->find($kind,$key)?->getData()??[];}
    public function all(string $kind): array{return array_map(fn(AiRecord $r)=>['key'=>$r->getRecordKey(),'revision'=>$r->getRevision()]+$r->getData(),$this->em->getRepository(AiRecord::class)->findBy(['kind'=>$kind],['id'=>'ASC']));}
    public function put(string $kind,string $key,array $data,?int $revision=null): AiRecord {
        $r=$this->find($kind,$key);if($revision!==null&&($r?->getRevision()??0)!==$revision)throw new InboxException('mautic.inbox.ai.conflict',409);
        $r??=(new AiRecord())->initialize($kind,$key);$r->change($data);$this->em->persist($r);$this->em->flush();return $r;
    }
    public function config(): array {
        $config=$this->get('config','global')+['enabled'=>false,'permissions'=>[],'model'=>'gpt-5.6-luna','provider'=>'openai-codex'];
        // Reply counts remain available for observability, but they never cap a support session.
        $config['limit']=0;
        $config['limit_action']='continue';
        return $config;
    }
    public function context(array $agent): array {
        $out=[];foreach($this->all('document') as $d){$v=$d['published']??null;if(!$v)continue;if(($v['scope']??'')==='global'||in_array($d['key'],$agent['documents']??[],true))$out[]=['key'=>$d['key'],'version'=>$v['version'],'name'=>$v['name'],'body'=>$v['body']];}
        if(mb_strlen(implode('',array_column($out,'body')))>60000)throw new InboxException('mautic.inbox.ai.context_large');return $out;
    }
    public function saveDocument(array $p,int $actor): array {
        $key=preg_match('/^[a-zA-Z0-9_-]{1,80}$/',(string)($p['key']??''))?$p['key']:bin2hex(random_bytes(8));$old=$this->get('document',$key);
        $body=trim((string)($p['body']??''));$name=trim((string)($p['name']??''));if(!$body||!$name||mb_strlen($body)>60000||mb_strlen($name)>100)throw new InboxException('mautic.inbox.ai.document_invalid');
        $draft=['name'=>$name,'body'=>$body,'scope'=>($p['scope']??'agent')==='global'?'global':'agent'];$data=$old+['versions'=>[]];$data['draft']=$draft;$data['updated_at']=gmdate(DATE_ATOM);$data['actor']=$actor;
        if(!empty($p['publish'])){$v=$draft+['version'=>count($data['versions'])+1,'actor'=>$actor,'date'=>gmdate(DATE_ATOM)];$data['versions'][]=$v;$data['published']=$v;}
        $r=$this->put('document',$key,$data,(int)($p['revision']??0));return ['key'=>$key,'revision'=>$r->getRevision()]+$data;
    }
}
