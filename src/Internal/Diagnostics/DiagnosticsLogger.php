<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Diagnostics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Picks the logger both failure reporters write to: the application's, or a `NullLogger`
 * when `diagnostics.enabled` is off.
 *
 * The switch is the injected object, as with `SignalMeter`, not a flag the reporters
 * carry. What gets silenced is exactly the log line: the reporters still swallow every
 * failure and still count it, because their protection must not depend on whether
 * anyone wants to read about it.
 */
final readonly class DiagnosticsLogger
{
    public static function create(LoggerInterface $logger, bool $enabled): LoggerInterface
    {
        return $enabled ? $logger : new NullLogger();
    }
}
