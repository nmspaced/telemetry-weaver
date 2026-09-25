<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ParametersConfigurator;

/**
 * Turns the validated configuration into one container parameter per leaf:
 * `logs.export.level` becomes `open_telemetry.logs.export.level`.
 *
 * Lists, and maps whose keys the application chooses, are single values.
 *
 * @internal
 */
final readonly class ConfigParameters
{
    /**
     * Maps with application-chosen keys, stored as one value.
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
