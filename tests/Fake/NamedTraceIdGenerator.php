<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Trace\IdGeneratorInterface;

/**
 * Trace ids with a recognisable prefix, standing in for the ones a backend imposes a shape on
 * — AWS X-Ray wants the start timestamp in the first four bytes.
 */
final class NamedTraceIdGenerator implements IdGeneratorInterface
{
    private int $sequence = 0;

    public function __construct(
        private readonly string $prefix = 'abcdef01',
    ) {}

    #[\Override]
    public function generateTraceId(): string
    {
        ++$this->sequence;

        return $this->prefix . \str_pad(\dechex($this->sequence), 24, '0', \STR_PAD_LEFT);
    }

    #[\Override]
    public function generateSpanId(): string
    {
        ++$this->sequence;

        return \str_pad(\dechex($this->sequence), 16, '0', \STR_PAD_LEFT);
    }
}
