<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * First terminal action wins. Detach only releases activation; abandon discards the measurement.
 *
 * @api
 */
interface RunningOperation extends OperationContext
{
    public function detach(): void;

    public function finish(?\Throwable $error = null): void;

    public function abandon(): void;
}
