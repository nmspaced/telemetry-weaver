<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * @api
 */
interface Duration
{
    public function start(): Measurement;
}
