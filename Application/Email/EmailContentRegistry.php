<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Email;

use MauticPlugin\MauticInboxBundle\Contract\EmailContentProviderInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Os providers de conteudo de e-mail, vistos como um so. Sem nenhum registrado (Multi Mail
 * ausente ou antigo), o Inbox continua funcionando: o original e os anexos aparecem como
 * indisponiveis, e nada lanca. Um provider que lanca e tratado do mesmo jeito, para que um
 * arquivo corrompido nunca derrube a linha do tempo inteira.
 */
final class EmailContentRegistry
{
    /** @var list<EmailContentProviderInterface> */
    private array $providers = [];

    /** @param iterable<EmailContentProviderInterface> $providers */
    public function __construct(#[TaggedIterator('mautic.inbox.email_content')] iterable $providers = [])
    {
        $seen = [];
        foreach ($providers as $provider) {
            // A mesma instancia pode chegar duas vezes quando o servico recebe a tag por
            // autoconfiguracao e tambem a mao.
            if (!$provider instanceof EmailContentProviderInterface || isset($seen[spl_object_id($provider)])) { continue; }
            $seen[spl_object_id($provider)] = true;
            $this->providers[] = $provider;
        }
    }

    public function supports(MetaMessage $message): bool
    {
        return null !== $this->provider($message);
    }

    public function originalHtml(MetaMessage $message): ?string
    {
        try {
            $html = $this->provider($message)?->originalHtml($message);
        } catch (\Throwable) {
            return null;
        }

        return is_string($html) && '' !== trim($html) ? $html : null;
    }

    /** @return array{path: string, mime: string, name: string, size: int}|null */
    public function attachment(MetaMessage $message, int $index): ?array
    {
        if ($index < 0) { return null; }
        try {
            $file = $this->provider($message)?->attachment($message, $index);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($file) || !is_string($file['path'] ?? null) || '' === $file['path']) { return null; }
        $path = $file['path'];
        // O provider diz que verificou; o Inbox confere de novo o minimo antes de entregar um
        // caminho de disco a uma resposta HTTP.
        if (is_link($path) || !is_file($path) || !is_readable($path)) { return null; }

        return [
            'path' => $path,
            'mime' => is_string($file['mime'] ?? null) ? $file['mime'] : 'application/octet-stream',
            'name' => is_string($file['name'] ?? null) && '' !== trim($file['name']) ? $file['name'] : 'anexo',
            'size' => (int) (filesize($path) ?: ($file['size'] ?? 0)),
        ];
    }

    private function provider(MetaMessage $message): ?EmailContentProviderInterface
    {
        foreach ($this->providers as $provider) {
            try {
                if ($provider->supports($message)) { return $provider; }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
