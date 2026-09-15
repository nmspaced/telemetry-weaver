<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->import('services/sdk.php');
    $container->import('services/diagnostics.php');
    $container->import('services/runtime.php');
    $container->import('services/public_api.php');
    $container->import('services/instrumentation/http_server.php');
    $container->import('services/instrumentation/http_client.php');
    $container->import('services/instrumentation/cache.php');
    $container->import('services/instrumentation/serializer.php');
    $container->import('services/instrumentation/doctrine.php');
    $container->import('services/instrumentation/messenger.php');
    $container->import('services/instrumentation/mailer.php');
    $container->import('services/instrumentation/scheduler.php');
    $container->import('services/instrumentation/console.php');
    $container->import('services/instrumentation/runtime.php');
};
