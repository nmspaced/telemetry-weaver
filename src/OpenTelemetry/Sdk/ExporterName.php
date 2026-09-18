<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Configuration\Configuration;

/**
 * Reads one OTEL_<SIGNAL>_EXPORTER variable, applying the rule the SDK's own factories
 * apply: exactly one exporter, and "none" means no exporter rather than an error.
 */
final readonly class ExporterName
{
    public const string OTLP = 'otlp';

    private const string NONE = 'none';

    /**
     * @param non-empty-string $variable
     *
     * @return non-empty-string|null null when the signal is switched off
     *
     * @throws \InvalidArgumentException when the configuration names more than one exporter
     */
    public static function of(string $variable): ?string
    {
        $exporters = Configuration::getList($variable);

        if (\count($exporters) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Configuration %s requires exactly 1 exporter', $variable));
        }

        /** @var mixed $exporter */
        $exporter = $exporters[0] ?? null;

        if (!\is_string($exporter) || $exporter === '' || $exporter === self::NONE) {
            return null;
        }

        return $exporter;
    }
}
