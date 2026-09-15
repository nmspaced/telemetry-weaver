<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;

/**
 * A collector that is down surfaces here: forceFlush() is where the blocking
 * export happens, so that is what has to be survivable.
 */
final readonly class ThrowingMeterProvider implements MeterProviderInterface
{
    public function __construct(
        private \Throwable $failure = new \RuntimeException('collector unreachable'),
    ) {}

    #[\Override]
    public function getMeter(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): MeterInterface {
        return new NoopMeter();
    }

    #[\Override]
    public function shutdown(): bool
    {
        return true;
    }

    /**
     * @throws \Throwable always — that is the whole point of this double
     */
    #[\Override]
    public function forceFlush(): bool
    {
        throw $this->failure;
    }

    #[\Override]
    public function updateConfigurator(Configurator $configurator): void {}
}
