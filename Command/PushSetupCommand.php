<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeyStore;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;
use MauticPlugin\MauticInboxBundle\Entity\PushSetting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mautic:inbox:push:setup', description: 'Create the push tables and the VAPID pair of this installation.')]
final class PushSetupCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private VapidKeyStore $keys,
        private CoreParametersHelper $parameters,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Gera um par novo. Toda inscricao existente morre.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 'mailto:' sem endereco passa pela validacao da fase 1 e depois volta como 401 da FCM,
        // que ninguem consegue diagnosticar. Melhor recusar aqui, alto e claro.
        $address = trim((string) $this->parameters->get('mailer_from_email'));
        if ('' === $address) {
            $io->error('O parametro mailer_from_email esta vazio. O VAPID exige um contato real; configure o e-mail de envio do Mautic antes de rodar este comando.');

            return Command::FAILURE;
        }

        $this->createMissingTables($io);

        $force = (bool) $input->getOption('force');
        if ($this->keys->isConfigured() && !$force) {
            $io->success('O par VAPID ja existe; nada foi tocado.');
            $io->writeln('Chave publica: '.$this->keys->load()->publicKey());
            $io->writeln('Assunto: '.($this->keys->subject() ?? '(nenhum)'));

            return Command::SUCCESS;
        }

        if ($this->keys->isConfigured()) {
            $io->warning('Gerar um par novo invalida TODAS as inscricoes ja existentes: cada navegador ja inscrito para de receber notificacoes ate se inscrever de novo.');
            if ($input->isInteractive() && !$io->confirm('Gerar mesmo assim?', false)) {
                $io->writeln('Nada foi alterado.');

                return Command::SUCCESS;
            }
        }

        $keys = $this->keys->generate();
        $this->keys->storeSubject('mailto:'.$address);

        $io->success('Par VAPID gravado com a privada cifrada em repouso.');
        $io->writeln('Chave publica: '.$keys->publicKey());
        $io->writeln('Assunto: mailto:'.$address);

        return Command::SUCCESS;
    }

    /**
     * O mautic:plugins:reload so instala o esquema na primeira instalacao do plugin: num plugin
     * ja instalado ele sai zero sem criar nada. Por isso as tabelas nascem aqui.
     */
    private function createMissingTables(SymfonyStyle $io): void
    {
        $manager = $this->em->getConnection()->createSchemaManager();
        $missing = [];
        foreach ([PushDevice::class, PushSetting::class] as $class) {
            $meta = $this->em->getClassMetadata($class);
            if (!$manager->tablesExist([$meta->getTableName()])) {
                $missing[] = $meta;
            }
        }

        if ([] === $missing) {
            return;
        }

        (new SchemaTool($this->em))->createSchema($missing);
        $io->writeln('Tabelas criadas: '.implode(', ', array_map(static fn ($meta) => $meta->getTableName(), $missing)));
    }
}
