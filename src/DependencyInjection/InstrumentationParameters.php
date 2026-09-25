<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ParametersConfigurator;

/**
 * Flattens the `instrumentation` tree into container parameters: one boolean per signal plus
 * each option.
 *
 * An option a signal can override is written both component-wide and per signal, so services
 * read one flat parameter.
 */
final readonly class InstrumentationParameters
{
    private const string PREFIX = 'open_telemetry.instrumentation.';

    /** @var list<string> */
    private const array SIGNALS = ['traces', 'metrics'];

    /**
     * @param array<non-empty-string, array<non-empty-string, mixed>> $instrumentation
     */
    public static function flatten(ParametersConfigurator $parameters, array $instrumentation): void
    {
        foreach ($instrumentation as $component => $options) {
            $prefix = \sprintf('%s%s', self::PREFIX, $component);

            /** @var mixed $value */
            foreach ($options as $option => $value) {
                if (\in_array($option, self::SIGNALS, true)) {
                    $parameters->set(\sprintf('%s.%s', $prefix, $option), self::enabled($value));

                    continue;
                }

                $parameters->set(\sprintf('%s.%s', $prefix, $option), $value);
                self::overrides($parameters, $prefix, $option, $value, $options);
            }
        }
    }

    /**
     * @param array<non-empty-string, mixed> $options
     */
    private static function overrides(
        ParametersConfigurator $parameters,
        string $prefix,
        string $option,
        mixed $value,
        array $options,
    ): void {
        foreach (self::SIGNALS as $signal) {
            /** @var mixed $signalOptions */
            $signalOptions = $options[$signal] ?? null;

            if (!\is_array($signalOptions)) {
                continue;
            }

            /** @var mixed $override */
            $override = $signalOptions[$option] ?? null;

            $parameters->set(\sprintf('%s.%s.%s', $prefix, $signal, $option), $override ?? $value);
        }
    }

    private static function enabled(mixed $signal): bool
    {
        return \is_array($signal) && ($signal['enabled'] ?? true) === true;
    }
}
