<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Diagnostics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The logger the failure reporters write to: the application's, or a `NullLogger` when
 * diagnostics are off. Failures are still swallowed and counted either way.
 */
final readonly class DiagnosticsLogger
{
    /** The bundle's own Monolog channel; never exported over OTLP, to avoid a feedback loop. */
    public const string CHANNEL = 'open_telemetry';

    public static function create(LoggerInterface $logger, bool $enabled): LoggerInterface
    {
        return $enabled ? $logger : new NullLogger();
    }
}
