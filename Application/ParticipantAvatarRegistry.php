<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use MauticPlugin\MauticInboxBundle\Contract\ParticipantAvatarProviderInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class ParticipantAvatarRegistry
{
    /** @var list<ParticipantAvatarProviderInterface> */
    private array $providers;

    public function __construct(#[TaggedIterator('mautic.inbox.participant_avatar')] iterable $providers)
    {
        $this->providers = iterator_to_array($providers, false);
    }

    public function url(MetaConversation $conversation): ?string
    {
        foreach ($this->providers as $provider) {
            $url = $provider->avatarUrl($conversation);
            if (is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
                return $url;
            }
        }

        return null;
    }
}
