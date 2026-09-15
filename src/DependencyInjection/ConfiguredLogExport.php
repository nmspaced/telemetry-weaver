<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * Whether the configuration asks for log export, answered before the extension has run.
 *
 * Needed because prepending happens before loading, and the one thing that has to be
 * decided at prepend time — whether to add a handler entry to MonologBundle's stack —
 * depends on a value that only exists after the tree is processed.
 *
 * The tree is therefore processed a second time here. That is the price of the ordering;
 * the alternative is reading the raw, unmerged extension configs and reimplementing the
 * merge, which is how a prepend comes to disagree with the extension it belongs to.
 *
 * A configuration that cannot be processed answers "no" rather than throwing. The same
 * tree is processed again moments later by the extension, and that is where a
 * configuration error belongs — reported against the configuration the user wrote, not
 * against a prepend they never asked for.
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
