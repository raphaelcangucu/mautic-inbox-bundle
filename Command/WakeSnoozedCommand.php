<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Command;

use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:inbox:wake', description: 'Reabre conversas cujo adiamento terminou.')]
final class WakeSnoozedCommand extends Command
{
    public function __construct(private ConversationActions $actions) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo por execução.', '500'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->actions->wakeDue(max(1, min(1000, (int) $input->getOption('limit'))));
        $output->writeln(sprintf('%d conversa(s) reaberta(s).', $count));
        return Command::SUCCESS;
    }
}
