<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Application\WhatsAppConversationMerger;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:inbox:deduplicate-whatsapp', description: 'Une conversas WhatsApp equivalentes, incluindo números brasileiros com ou sem o nono dígito.')]
final class DeduplicateWhatsAppConversationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WhatsAppConversationMerger $merger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('asset-id', null, InputOption::VALUE_REQUIRED, 'Limita a análise ao ID interno de um ativo Meta.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Grava as uniões seguras; sem esta opção apenas mostra a prévia.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asset = null;
        if (null !== $input->getOption('asset-id')) {
            $asset = $this->entityManager->find(MetaAsset::class, (int) $input->getOption('asset-id'));
            if (!$asset instanceof MetaAsset) {
                $output->writeln('<error>O ativo informado não existe.</error>');

                return Command::INVALID;
            }
        }

        $groups = $this->merger->duplicateGroups($asset);
        if ([] === $groups) {
            $output->writeln('Nenhuma conversa WhatsApp duplicada foi encontrada.');

            return Command::SUCCESS;
        }

        foreach ($groups as $group) {
            $output->writeln(sprintf(
                'Ativo %d · %s · conversas %s · contatos %s · %s',
                $group['asset_id'],
                $group['canonical_recipient'],
                implode(', ', $group['conversation_ids']),
                [] === $group['contact_ids'] ? 'sem vínculo' : implode(', ', $group['contact_ids']),
                $group['safe'] ? 'segura para unir' : 'CONFLITO DE CONTATOS',
            ));
        }
        if (!$input->getOption('apply')) {
            $output->writeln('Prévia concluída. Use --apply para gravar somente os grupos seguros.');

            return Command::SUCCESS;
        }

        $merged = 0;
        foreach ($groups as $group) {
            if (!$group['safe']) {
                continue;
            }
            $result = $this->merger->merge($group['conversation_ids'], $group['canonical_recipient']);
            ++$merged;
            $output->writeln(sprintf(
                '<info>Conversa %d preservada; %s unida(s); %d mensagem(ns) transferida(s).</info>',
                $result['primary_conversation_id'],
                implode(', ', $result['merged_conversation_ids']),
                $result['messages'],
            ));
        }
        $output->writeln(sprintf('%d grupo(s) unido(s).', $merged));

        return Command::SUCCESS;
    }
}
