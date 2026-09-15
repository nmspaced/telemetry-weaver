<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reads the switches a compiler pass needs before it registers anything.
 *
 * Shared because all three instrumentation passes ask the same two questions in the
 * same way, and a copy of the answer in each was how the global `traces.enabled` came
 * to be forgotten in one of them.
 *
 * A missing parameter is a "no": the extension only writes these when the bundle is
 * enabled, so their absence means there is nothing to instrument.
 */
final readonly class SignalSwitch
{
    public static function bundleEnabled(ContainerBuilder $container): bool
    {
        return self::on($container, 'open_telemetry.enabled');
    }

    /**
     * Both switches have to be on: the signal's own and the component's.
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
     * The only question a decorating pass has to ask: is there anything to record for
     * this component at all.
     *
     * Three switches, one answer. The bundle's own comes first — with it off, services.php
     * is never imported and the telemetry service the decorator takes as an argument does
     * not exist. Then either signal is reason enough to wrap, because the wrapper a pass
     * registers — a middleware, a decorator — usually carries both, so a pass that asks
     * only about traces switches the component's metrics off along with them. Asking it
     * once, here, is what keeps the next pass from getting it wrong too.
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

    public static function on(ContainerBuilder $container, string $parameter): bool
    {
        return $container->hasParameter($parameter) && $container->getParameter($parameter) === true;
    }
}
