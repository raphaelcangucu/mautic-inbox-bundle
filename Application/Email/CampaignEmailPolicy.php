<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Email;

/**
 * O que a acao de campanha "Iniciar conversa por e-mail" faz com cada contato do lote, sem
 * banco. Sao as quatro saidas da spec, mais a do e-mail que ja pertence a outro contato.
 */
final class CampaignEmailPolicy
{
    public const NO_EMAIL = 'no_email';
    public const DNC = 'dnc';
    public const IN_SERVICE = 'in_service';
    public const OTHER_CONTACT = 'other_contact';
    public const SEND = 'send';

    /**
     * @param array{lifecycle:string,assigned:bool,takeover:bool}|null $state a conversa (conta, e-mail) que ja existe, se existe
     */
    public static function outcome(?string $recipient, bool $doNotContact, ?array $state, ?int $linkedContact, int $contact): string
    {
        if (null === $recipient) { return self::NO_EMAIL; }
        if ($doNotContact) { return self::DNC; }
        // Campanha nao fala por cima de uma pessoa: conversa aberta, com atendente e assumida.
        if (null !== $state && 'resolved' !== $state['lifecycle'] && $state['assigned'] && $state['takeover']) { return self::IN_SERVICE; }
        if (null !== $linkedContact && $linkedContact !== $contact) { return self::OTHER_CONTACT; }

        return self::SEND;
    }

    /** Reexecutar o mesmo evento do mesmo contato nao duplica o e-mail: a fila devolve o job. */
    public static function idempotencyKey(int $logId): string
    {
        return 'campaign:'.$logId;
    }
}
