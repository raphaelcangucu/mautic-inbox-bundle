<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    $excludes = MauticCoreExtension::DEFAULT_EXCLUDES;
    $excludes[] = 'Application/InboxException.php';
    $excludes[] = 'Application/Push/VapidKeys.php'; // construtor privado: nao e servico
    $excludes[] = 'DependencyInjection/Compiler';
    $services->load('MauticPlugin\\MauticInboxBundle\\', '../')->exclude('../{'.implode(',', $excludes).'}');
    $services->load('MauticPlugin\\MauticInboxBundle\\Entity\\', '../Entity/*Repository.php')->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);
};
