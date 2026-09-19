<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * First terminal action wins; abandon discards the measurement.
 *
 * @api
 */
interface RunningOperation extends OperationContext
{
    public function finish(?\Throwable $error = null): void;

    public function abandon(): void;
}
