<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;

/** The metadata a reader hands a temporality selector, for an instrument kind chosen by the test. */
final readonly class InstrumentMetadata implements MetricMetadataInterface
{
    public function __construct(
        private string $instrumentType,
        private string $temporality = Temporality::DELTA,
    ) {}

    #[\Override]
    public function instrumentType(): string
    {
        return $this->instrumentType;
    }

    #[\Override]
    public function name(): string
    {
        return 'probe';
    }

    #[\Override]
    public function unit(): ?string
    {
        return null;
    }

    #[\Override]
    public function description(): ?string
    {
        return null;
    }

    #[\Override]
    public function temporality(): string
    {
        return $this->temporality;
    }
}
