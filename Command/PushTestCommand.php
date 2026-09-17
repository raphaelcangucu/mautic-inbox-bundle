<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Command;

use Mautic\UserBundle\Model\UserModel;
use MauticPlugin\MauticInboxBundle\Application\Push\PushPayload;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSender;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSubscriptions;
use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeyStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envia uma notificacao de teste para os aparelhos de um usuario.
 *
 * Existe para separar defeito de entrega de defeito de fluxo. Se este comando diz entregue e
 * nada aparece na tela, o problema esta no service worker; se diz 401, esta no VAPID; se diz
 * 400, esta na cifra. Sem ele, os tres se parecem.
 */
#[AsCommand(name: 'mautic:inbox:push:test', description: 'Send a test notification to one user\'s devices.')]
final class PushTestCommand extends Command
{
    public function __construct(
        private UserModel $users,
        private PushSubscriptions $subscriptions,
        private PushSender $sender,
        private VapidKeyStore $keys,
        private UrlGeneratorInterface $router,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'ID ou e-mail do usuario.');
        $this->addOption('message', null, InputOption::VALUE_REQUIRED, 'Texto da notificacao.', 'Chegou uma mensagem nova no atendimento.');
        $this->addOption('conversation', null, InputOption::VALUE_REQUIRED, 'ID da conversa, para o aviso abrir nela e realertar como o de verdade.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->keys->isConfigured()) {
            $io->error('Nenhum par VAPID configurado. Rode mautic:inbox:push:setup antes.');

            return Command::FAILURE;
        }

        $identifier = trim((string) $input->getOption('user'));
        if ('' === $identifier) {
            $io->error('Informe --user com o ID ou o e-mail do atendente.');

            return Command::FAILURE;
        }

        $user = ctype_digit($identifier)
            ? $this->users->getEntity((int) $identifier)
            : $this->users->getRepository()->findOneBy(['email' => $identifier]);

        if (null === $user) {
            $io->error(sprintf('Usuario "%s" nao encontrado.', $identifier));

            return Command::FAILURE;
        }

        $devices = $this->subscriptions->activeFor($user);
        if ([] === $devices) {
            $io->warning(sprintf('%s nao tem nenhum aparelho inscrito. Abra /s/inbox nele e ligue a notificacao nos ajustes.', $user->getEmail()));

            return Command::SUCCESS;
        }

        // Monta pelo MESMO caminho que uma mensagem de verdade usa. Antes isto era um JSON
        // escrito a mao, e o teste deixava de exercitar justamente o que podia quebrar: a
        // truncagem, a tag por conversa e o vinculo com o destino. Sem a tag de conversa, o
        // Android trata a notificacao seguinte como atualizacao da anterior e nao realerta —
        // o comando dizia "entregue" e a tela nao piscava.
        $conversa = (int) $input->getOption('conversation');
        $payload  = PushPayload::forInboundMessage(
            'Teste do atendimento',
            (string) $input->getOption('message'),
            $conversa,
            $conversa > 0
                ? $this->router->generate('mautic_inbox_app_conversation', ['stateId' => $conversa])
                : $this->router->generate('mautic_inbox_app'),
        );

        $entregues = 0;
        $linhas    = [];

        foreach ($devices as $device) {
            $result = $this->sender->send($device, $payload);
            $entregues += $result->delivered ? 1 : 0;

            $linhas[] = [
                (string) $device->getId(),
                $this->shorten($device->getEndpoint()),
                substr((string) $device->getUserAgent(), 0, 40),
                $result->delivered ? 'entregue' : 'falhou',
                (string) $result->statusCode,
                $result->message,
            ];
        }

        $io->table(['id', 'endpoint', 'aparelho', 'resultado', 'http', 'detalhe'], $linhas);

        if (0 === $entregues) {
            $io->error('Nenhuma entrega aceita pelo servico de push.');

            return Command::FAILURE;
        }

        $io->success(sprintf('%d de %d aparelho(s) aceitaram a notificacao. Ela deve aparecer na tela agora.', $entregues, count($devices)));

        return Command::SUCCESS;
    }

    private function shorten(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?: 'desconhecido';

        return $host.'/…'.substr($endpoint, -12);
    }
}
