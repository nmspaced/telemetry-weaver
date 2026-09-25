<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * Whether the configuration enables log export, answered at prepend time.
 *
 * Processes the tree once more because prepend runs before the extension. An invalid
 * configuration answers no; the extension reports the error.
 */
final readonly class ConfiguredLogExport
{
    public static function isRequested(?ExtensionInterface $extension, ContainerBuilder $container): bool
    {
        if (!$extension instanceof ConfigurationExtensionInterface) {
            return false;
        }

        $configs = $container->getExtensionConfig($extension->getAlias());
        $configuration = $extension->getConfiguration($configs, $container);

        if ($configuration === null) {
            return false;
        }

        try {
            /** @var array<array-key, mixed> $resolved */
            $resolved = $container->getParameterBag()->resolveValue($configs);
            /** @var array{enabled?: bool, logs?: array{export?: array{enabled?: bool}}} $config */
            $config = new Processor()->processConfiguration($configuration, $resolved);
        } catch (\Throwable) {
            return false;
        }

        return ($config['enabled'] ?? false) && ($config['logs']['export']['enabled'] ?? false);
    }
}
