<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\EventListener;

use MauticPlugin\MauticInboxBundle\Application\Push\PendingNotifications;
use MauticPlugin\MauticInboxBundle\Application\Push\PushAudience;
use MauticPlugin\MauticInboxBundle\Application\Push\PushDeviceStore;
use MauticPlugin\MauticInboxBundle\Application\Push\PushPayload;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSender;
use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeyStore;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Manda as notificacoes DEPOIS que a resposta ja saiu.
 *
 * O webhook da Meta precisa responder rapido, e ela repete a entrega quando demoramos. Por
 * isso nada aqui roda durante o request: kernel.terminate acontece com a resposta ja no fio.
 * O gemeo de console existe porque mensagem tambem chega pelo worker da fila.
 *
 * Nada aqui pode lancar. Push quebrado nao pode impedir a gravacao da mensagem de um cliente
 * nem atrasar o 200 para a Meta.
 */
final class PushDispatchSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PendingNotifications $pending,
        private ConversationStateRepository $states,
        private PushAudience $audience,
        private PushSender $sender,
        private PushDeviceStore $devices,
        private VapidKeyStore $keys,
        private UrlGeneratorInterface $router,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE  => ['dispatch', 0],
            ConsoleEvents::TERMINATE => ['dispatch', 0],
        ];
    }

    public function dispatch(): void
    {
        $pending = $this->pending->drain();
        if ([] === $pending) {
            return;
        }

        try {
            if (!$this->keys->isConfigured()) {
                return;
            }

            foreach ($pending as $item) {
                $this->notify($item['stateId'], $item['contact'], $item['preview']);
            }
        } catch (\Throwable $problem) {
            $this->logger->error('inbox.push: falha ao despachar notificacoes', ['exception' => $problem]);
        }
    }

    private function notify(int $stateId, string $contact, string $preview): void
    {
        $state = $this->states->find($stateId);
        if (null === $state) {
            return;
        }

        $devices = $this->audience->devicesFor($state->getAssignee());
        if ([] === $devices) {
            return;
        }

        $payload = PushPayload::forInboundMessage(
            $contact,
            $preview,
            $stateId,
            // Aponta para o shell do app, nao para a tela do Mautic: quem tocou numa
            // notificacao esta no celular, e o destino certo e o app instalado.
            $this->router->generate('mautic_inbox_app_conversation', ['stateId' => $stateId]),
        );

        foreach ($devices as $device) {
            $result = $this->sender->send($device, $payload);

            if ($result->delivered) {
                $device->recordSuccess();
            } elseif ($result->retireDevice) {
                // A inscricao morreu com a desinstalacao. Apagar e o certo: manter uma linha
                // morta so gera tentativa inutil a cada mensagem.
                $this->devices->remove($device);
                continue;
            } else {
                $device->recordFailure();
                $this->logger->warning('inbox.push: entrega recusada', [
                    'device' => $device->getId(),
                    'status' => $result->statusCode,
                    'motivo' => $result->message,
                ]);
            }

            $this->devices->save($device);
        }
    }
}
