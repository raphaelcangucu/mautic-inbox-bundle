<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * As notificacoes que este request ainda vai mandar.
 *
 * Registrar aqui custa uma linha em memoria. E de proposito: o registro acontece dentro do
 * fluxo que grava a mensagem recebida do cliente, e aquele fluxo nao pode pagar rede, nem
 * criptografia, nem risco de excecao. O envio de verdade so comeca depois que a resposta ja
 * foi entregue a Meta.
 */
final class PendingNotifications
{
    /** @var list<array{stateId:int, contact:string, preview:string}> */
    private array $pending = [];

    public function add(int $stateId, string $contact, string $preview): void
    {
        if ($stateId <= 0) {
            return;
        }

        $this->pending[] = ['stateId' => $stateId, 'contact' => $contact, 'preview' => $preview];
    }

    /**
     * Devolve o que havia e esvazia, para que um segundo despacho no mesmo processo nao
     * reenvie o que ja saiu.
     *
     * @return list<array{stateId:int, contact:string, preview:string}>
     */
    public function drain(): array
    {
        $pending       = $this->pending;
        $this->pending = [];

        return $pending;
    }
}
