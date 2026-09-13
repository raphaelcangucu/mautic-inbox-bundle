<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticInboxBundle\DependencyInjection\Compiler\MetaInboxIntegrationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MauticInboxBundle extends PluginBundleBase
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MetaInboxIntegrationPass());
    }
}
