<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ParametersConfigurator;

/**
 * Turns the validated configuration into container parameters, one per leaf, named by its
 * path: `logs.export.level` becomes `open_telemetry.logs.export.level`.
 *
 * It used to be a hand-written `->set()` per key. Every one of them was that same literal
 * mapping, and the failure mode was always the same: a key passes validation, nothing sets
 * the parameter, and the service reading it gets the container's default instead of what
 * the application wrote. That is the most frequent defect this package has had, and a test
 * can only catch the instances someone remembered to write. Here it cannot be expressed —
 * a leaf is a parameter because it is a leaf.
 *
 * ## Where a subtree stops
 *
 * A list is a value: `excluded_paths`, `excluded_channels`. So is a map whose *keys belong
 * to the application* — resource attributes and OTLP headers are data the user names, and
 * descending into them would mint a parameter per user-chosen key. Everything else is
 * structure the configuration tree fixed, and is descended into.
 *
 * Empty needs no special case: `[]` is a list.
 *
 * @internal
 */
final readonly class ConfigParameters
{
    /**
     * Maps whose keys the application chooses, so the map itself is the value.
     *
     * Only maps need naming — a list stops the descent on its own. Add a key here when the
     * configuration grows another `useAttributeAsKey()` node; forgetting produces a visible
     * pile of parameters named after user data, not a silently missing one.
     *
     * @var list<string>
     */
    private const array VALUE_MAPS = ['sdk.resource_attributes', 'sdk.exporter_otlp_headers'];

    /**
     * @param non-empty-string $prefix
     * @param array<array-key, mixed> $config
     * @param list<string> $except top-level keys with a flattener of their own
     */
    public static function flatten(
        ParametersConfigurator $parameters,
        string $prefix,
        array $config,
        array $except = [],
    ): void {
        /** @var mixed $value */
        foreach ($config as $key => $value) {
            if (!\is_string($key) || \in_array($key, $except, true)) {
                continue;
            }

            self::set($parameters, $prefix, $key, $value);
        }
    }

    /**
     * @param non-empty-string $prefix
     */
    private static function set(ParametersConfigurator $parameters, string $prefix, string $path, mixed $value): void
    {
        if (\is_array($value) && !\array_is_list($value) && !\in_array($path, self::VALUE_MAPS, true)) {
            /** @var mixed $child */
            foreach ($value as $key => $child) {
                if (!\is_string($key)) {
                    continue;
                }

                self::set($parameters, $prefix, \sprintf('%s.%s', $path, $key), $child);
            }

            return;
        }

        $parameters->set(\sprintf('%s.%s', $prefix, $path), $value);
    }
}
