<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\MetricReaderInterface;
use OpenTelemetry\SDK\Metrics\MetricSourceProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricSourceRegistryInterface;
use OpenTelemetry\SDK\Metrics\StalenessHandlerInterface;

/**
 * Reader that counts calls: a decorator must forward all of them.
 */
final class RecordingMetricReader implements MetricReaderInterface, MetricSourceRegistryInterface
{
    public int $collected = 0;

    public int $registered = 0;

    public bool $shutdownCalled = false;

    public bool $flushCalled = false;

    #[\Override]
    public function add(
        MetricSourceProviderInterface $provider,
        MetricMetadataInterface $metadata,
        StalenessHandlerInterface $stalenessHandler,
    ): void {
        ++$this->registered;
    }

    #[\Override]
    public function collect(): bool
    {
        ++$this->collected;

        return true;
    }

    #[\Override]
    public function shutdown(): bool
    {
        $this->shutdownCalled = true;

        return true;
    }

    #[\Override]
    public function forceFlush(): bool
    {
        $this->flushCalled = true;

        return true;
    }
}
