<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\DependencyInjection\Compiler;

use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MetaInboxIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(MetaInboxIntegration::class) && $container->has(InboxIntegrationInterface::class)) {
            $container->setAlias(InboxIntegrationInterface::class, MetaInboxIntegration::class)->setPublic(true);
        }
    }
}
