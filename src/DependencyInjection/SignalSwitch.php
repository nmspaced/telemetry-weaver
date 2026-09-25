<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reads the configuration switches compiler passes decide on.
 *
 * A missing parameter means off: the extension only writes them when the bundle is enabled.
 */
final readonly class SignalSwitch
{
    public static function bundleEnabled(ContainerBuilder $container): bool
    {
        return self::on($container, 'open_telemetry.enabled');
    }

    /**
     * Both the signal's global switch and the component's own must be on.
     *
     * @param non-empty-string $signal traces or metrics
     * @param non-empty-string $component
     */
    public static function signal(ContainerBuilder $container, string $signal, string $component): bool
    {
        return (
            self::on($container, \sprintf('open_telemetry.%s.enabled', $signal))
            && self::on($container, \sprintf('open_telemetry.instrumentation.%s.%s', $component, $signal))
        );
    }

    /**
     * Whether the bundle is on and the component records at least one signal.
     *
     * @param non-empty-string $component
     */
    public static function instrumented(ContainerBuilder $container, string $component): bool
    {
        return (
            self::bundleEnabled($container)
            && (self::signal($container, 'traces', $component) || self::signal($container, 'metrics', $component))
        );
    }

    /**
     * Whether the bundle is on and at least one of the component's own switches is.
     *
     * The global signal switches are ignored: they stop recording, not context propagation.
     *
     * @param non-empty-string $component
     */
    public static function carriesContext(ContainerBuilder $container, string $component): bool
    {
        return (
            self::bundleEnabled($container)
            && (
                self::on($container, \sprintf('open_telemetry.instrumentation.%s.traces', $component))
                || self::on($container, \sprintf('open_telemetry.instrumentation.%s.metrics', $component))
            )
        );
    }

    public static function on(ContainerBuilder $container, string $parameter): bool
    {
        return $container->hasParameter($parameter) && $container->getParameter($parameter) === true;
    }
}
