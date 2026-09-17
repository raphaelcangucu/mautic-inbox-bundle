<?php
namespace MauticPlugin\MauticInboxBundle\Command;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use MauticPlugin\MauticInboxBundle\Entity\AiRecord;
use MauticPlugin\MauticInboxBundle\Application\Ai\AiStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'mautic:inbox:ai:setup',description:'Create the isolated AI storage and initial documents.')]
final class AiSetupCommand extends Command {
 public function __construct(private EntityManagerInterface $em,private AiStore $store){parent::__construct();}
 protected function execute(InputInterface $i,OutputInterface $o): int {
  $meta=$this->em->getClassMetadata(AiRecord::class);if(!$this->em->getConnection()->createSchemaManager()->tablesExist([$meta->getTableName()]))(new SchemaTool($this->em))->createSchema([$meta]);
  foreach(glob(__DIR__.'/../docs/ai/*.md') as $file){$key=pathinfo($file,PATHINFO_FILENAME);if(!$this->store->find('document',$key))$this->store->saveDocument(['key'=>$key,'name'=>basename($file),'body'=>file_get_contents($file),'scope'=>in_array($key,['identidade','atendimento','fontes','conversao'])?'global':'agent','publish'=>true,'revision'=>0],0);}
  if(!$this->store->find('agent','macro'))$this->store->put('agent','macro',['name'=>'Agente Macro','profile'=>'macro-support','enabled'=>true,'limit'=>0,'limit_configured'=>true,'documents'=>['analises-esportivas','plataforma'],'permissions'=>[]]);
  if(!$this->store->find('config','global')){$config=$this->store->config();$config['limit_configured']=true;$this->store->put('config','global',$config);}$o->writeln('AI storage ready. Explicit assignment required.');return 0;
 }
}
