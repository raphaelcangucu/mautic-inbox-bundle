<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

/**
 * As opcoes "email:{assetId}" do sheet de canais (plano, secao 1.6), sem banco: quem consulta
 * entrega as contas, o e-mail do contato e o DNC, e aqui so se decide o que mostrar.
 */
final class EmailStartOptions
{
    public const NO_EMAIL = 'Cadastre um e-mail válido no contato.';
    public const DNC = 'O contato pediu para não receber e-mails.';
    public const INACTIVE = 'Esta conta de e-mail está desativada.';

    /**
     * O destinatario da conversa: o e-mail do contato em minusculas, que e a chave
     * (asset, canal, recipient) do Meta. Null quando nao ha e-mail utilizavel.
     */
    public static function recipient(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return '' !== $email && strlen($email) <= 254 && false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @param list<array{id:int,name:string,address:?string,active:bool}> $accounts
     * @param list<int>                                                    $skip     contas que ja tem conversa com o contato, e que aparecem como state:
     * @return list<array{key:string,state_id:null,asset_id:int,channel:string,name:string,address:?string,available:bool,reason:?string}>
     */
    public static function build(array $accounts, ?string $contactEmail, bool $doNotContact, bool $canStart, array $skip = []): array
    {
        $recipient = self::recipient($contactEmail);
        $items = [];
        foreach ($accounts as $account) {
            if (in_array($account['id'], $skip, true)) { continue; }
            $reason = match (true) {
                !$account['active'] => self::INACTIVE,
                null === $recipient => self::NO_EMAIL,
                $doNotContact => self::DNC,
                default => null,
            };
            $address = null !== $account['address'] && '' !== $account['address'] ? strtolower($account['address']) : null;
            $items[] = [
                'key' => 'email:'.$account['id'],
                'state_id' => null,
                'asset_id' => $account['id'],
                'channel' => 'email',
                'name' => null !== $address ? $account['name'].' · '.$address : $account['name'],
                'address' => $address,
                'available' => $canStart && null === $reason,
                'reason' => $reason,
            ];
        }

        return $items;
    }
}
