<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

/** Drops spans by name, a decision `OTEL_TRACES_SAMPLER` cannot express. */
final readonly class NameBasedSampler implements SamplerInterface
{
    /**
     * @param list<string> $dropped
     */
    public function __construct(
        private array $dropped = [],
    ) {}

    /**
     * @param list<LinkInterface> $links
     */
    // @mago-expect lint:excessive-parameter-list — SamplerInterface's signature, not ours to change
    #[\Override]
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        return new SamplingResult(
            \in_array($spanName, $this->dropped, true) ? SamplingResult::DROP : SamplingResult::RECORD_AND_SAMPLE,
        );
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'NameBasedSampler{' . \implode(',', $this->dropped) . '}';
    }
}
