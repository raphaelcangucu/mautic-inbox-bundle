<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use MauticPlugin\MauticInboxBundle\Contract\ChannelTransportInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class ChannelTransportRegistry
{
    /** @var list<ChannelTransportInterface> */
    private array $transports;

    /** @param iterable<ChannelTransportInterface> $transports */
    public function __construct(#[TaggedIterator('mautic.inbox.channel_transport')] iterable $transports)
    {
        $this->transports = iterator_to_array($transports, false);
    }

    public function for(MetaConversation $conversation): ?ChannelTransportInterface
    {
        foreach ($this->transports as $transport) {
            if ($transport->supports($conversation)) {
                return $transport;
            }
        }

        return null;
    }
}
