<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use MauticPlugin\MauticInboxBundle\Contract\AttachmentProviderInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class AttachmentRegistry
{
    /** @var list<AttachmentProviderInterface> */
    private array $providers;

    public function __construct(#[TaggedIterator('mautic.inbox.attachment')] iterable $providers)
    {
        $this->providers = iterator_to_array($providers, false);
    }

    public function url(MetaMessage $message, string $type): ?string
    {
        foreach ($this->providers as $provider) {
            $url = $provider->attachmentUrl($message, $type);
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) { continue; }
            $parts = parse_url($url);
            if ('https' === ($parts['scheme'] ?? '') && !isset($parts['user']) && !isset($parts['pass'])) { return $url; }
        }

        return null;
    }
}
