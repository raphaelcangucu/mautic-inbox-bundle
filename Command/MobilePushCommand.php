<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Command;
use MauticPlugin\MauticInboxBundle\Application\Mobile\Push\{NativePushWorker,PrivateStorage};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'mautic:inbox:mobile-push', description:'Deliver queued native iOS notifications through APNs. No schema changes.')]
final class MobilePushCommand extends Command
{
    public function __construct(private NativePushWorker $worker, private PrivateStorage $storage) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum jobs per pass',50)->addOption('watch',null,InputOption::VALUE_REQUIRED,'Poll for at most 55 seconds',0); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit=max(1,min(500,(int)$input->getOption('limit'))); $watch=max(0,min(55,(int)$input->getOption('watch')));
        $this->storage->transaction(static fn(array &$data): bool => true);
        $file=$this->storage->directory.'/native-push-worker.lock'; $lock=fopen($file,'c');
        if (!$lock) { return Command::FAILURE; } chmod($file,0600);
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return Command::SUCCESS; }
        $deadline=microtime(true)+$watch;
        try { do { $result=$this->worker->run($limit); if (array_sum($result)>0) { $output->writeln(json_encode($result,JSON_THROW_ON_ERROR)); } if (microtime(true)+2>$deadline) { break; } sleep(2); } while (true); return Command::SUCCESS; }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
