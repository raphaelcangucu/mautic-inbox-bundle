<?php
namespace MauticPlugin\MauticInboxBundle\Command;
use MauticPlugin\MauticInboxBundle\Application\Ai\AiWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'mautic:inbox:ai:work',description:'Process explicitly assigned AI conversations and enabled comment automations.')]
final class AiWorkCommand extends Command {public function __construct(private AiWorker $worker,private \MauticPlugin\MauticInboxBundle\Application\Ai\CommentAutomation $comments){parent::__construct();}protected function execute(InputInterface $i,OutputInterface $o):int{$o->writeln(json_encode(['conversations'=>$this->worker->work(),'comments'=>$this->comments->work()]));return 0;}}
