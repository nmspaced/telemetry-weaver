<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\LoggerHolder;
use Psr\Log\LoggerInterface;

/**
 * Routes the SDK's own diagnostics into the application's logger instead of `error_log()`.
 *
 * Never unset: the final flush of a worker runs at PHP shutdown, after the kernel is gone.
 */
final readonly class SdkDiagnostics
{
    public static function install(LoggerInterface $logger): void
    {
        LoggerHolder::set($logger);
    }
}
