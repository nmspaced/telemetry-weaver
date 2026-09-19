<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\LoggerHolder;
use Psr\Log\LoggerInterface;

/**
 * Routes the OpenTelemetry SDK's own diagnostics into the application's logger.
 *
 * Without this the SDK is not silent — `LogWriterFactory` falls back to `ErrorLogWriter`
 * when no logger is registered — it is *elsewhere*. Exporter, transport and factory
 * warnings go to `error_log()`, outside Monolog, with no channel, no processors and no
 * trace correlation, while this bundle's own failure reports go through a rate-limited
 * PSR logger a few lines away. An operator reading one of those two streams was missing
 * the other, and the SDK's is the half that says the collector refused the batch.
 *
 * Process-global state, like {@see GlobalsRegistrar}, and claimed at boot for the same
 * reason: a container is a description of services until the kernel boots one.
 *
 * It is never unset. The tempting symmetry — release it on kernel shutdown — would
 * silence exactly the diagnostics worth having, because a worker's final flush runs on
 * PHP shutdown, after the kernel is gone, and that flush is the one whose failure nobody
 * else will report. A worker that reboots its kernel per request replaces the logger on
 * the next boot, so the previous container is held for at most one request.
 */
final readonly class SdkDiagnostics
{
    public static function install(LoggerInterface $logger): void
    {
        LoggerHolder::set($logger);
    }
}
