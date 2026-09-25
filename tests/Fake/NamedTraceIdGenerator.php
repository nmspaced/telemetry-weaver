<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Trace\IdGeneratorInterface;

/** Generates trace ids with a fixed prefix, like backends that require a shape. */
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
