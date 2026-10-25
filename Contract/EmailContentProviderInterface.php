<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Contract;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/**
 * O conteudo de um e-mail que nao cabe no payload: o HTML original completo e os anexos, que
 * o canal guarda em arquivo privado. A AttachmentProviderInterface nao serve aqui: devolve uma
 * URL por mensagem e proibe IO, e um e-mail tem varios anexos e um original guardado em disco.
 *
 * O Inbox serve o arquivo com os proprios cabecalhos de seguranca e a propria checagem de
 * acesso; o provider so diz onde ele esta.
 *
 * Tag: mautic.inbox.email_content
 */
interface EmailContentProviderInterface
{
    public function supports(MetaMessage $message): bool;

    /** HTML original completo, cru (o Inbox saneia), ou null. Pode fazer IO. */
    public function originalHtml(MetaMessage $message): ?string;

    /**
     * Arquivo local verificado de um anexo. Null se nao existir ou nao estiver disponivel.
     *
     * @return array{path: string, mime: string, name: string, size: int}|null
     */
    public function attachment(MetaMessage $message, int $index): ?array;
}
