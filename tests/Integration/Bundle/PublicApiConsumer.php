<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;

final readonly class PublicApiConsumer
{
    public function __construct(
        public Telemetry $telemetry,
        public TelemetryFactory $factory,
        public ActiveTrace $activeTrace,
    ) {}
}
