<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticInboxBundle\Application\Push\PushDeviceStore;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSettingStore;
use MauticPlugin\MauticInboxBundle\Entity\PushDeviceRepository;
use MauticPlugin\MauticInboxBundle\Entity\PushSettingRepository;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    $excludes = MauticCoreExtension::DEFAULT_EXCLUDES;
    $excludes[] = 'Application/InboxException.php';
    $excludes[] = 'Application/Push/VapidKeys.php'; // construtor privado: nao e servico
    $excludes[] = 'DependencyInjection/Compiler';
    $services->load('MauticPlugin\\MauticInboxBundle\\', '../')->exclude('../{'.implode(',', $excludes).'}');
    $services->load('MauticPlugin\\MauticInboxBundle\\Entity\\', '../Entity/*Repository.php')->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);
    // O apelido e explicito porque a interface e o repositorio entram por chamadas de load
    // diferentes, e o apelido automatico do Symfony so vale dentro de uma mesma chamada.
    $services->alias(PushSettingStore::class, PushSettingRepository::class);
    $services->alias(PushDeviceStore::class, PushDeviceRepository::class);
};
