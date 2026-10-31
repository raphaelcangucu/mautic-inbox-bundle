<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Email;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\InboxException;

/** As regras puras da resposta por e-mail: assunto e nome de quem assina. */
final class EmailReply
{
    public const MAX_SUBJECT = 255;

    /**
     * O assunto que sai. Vazio vira "Re: <assunto da ultima mensagem>", sem empilhar "Re: Re:".
     * Quebra de linha nunca passa: assunto vira cabecalho, e um \r\n ali e injecao de cabecalho.
     */
    public static function subject(?string $requested, ?string $previous): string
    {
        $subject = self::clean($requested);
        if ('' !== $subject) { return $subject; }
        $previous = self::clean($previous);
        if ('' === $previous) { throw new InboxException('mautic.inbox.ui.email_subject_required', 422); }

        return preg_match('/^(re|res|aw|sv|antw|r)\s*:/iu', $previous) ? $previous : mb_substr('Re: '.$previous, 0, self::MAX_SUBJECT);
    }

    public static function clean(?string $subject): string
    {
        $subject = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $subject);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $subject)), 0, self::MAX_SUBJECT);
    }

    /** O primeiro nome, que e como o atendente assina ("Ana · Macro Markets"). */
    public static function authorName(User $author): string
    {
        $name = trim((string) $author->getFirstName()) ?: trim((string) $author->getName()) ?: trim((string) $author->getUsername());

        return self::clean($name);
    }
}
