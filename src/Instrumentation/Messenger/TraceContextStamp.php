<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the propagator's headers from the dispatching process to the worker.
 *
 * The class name is part of the serialized wire format: do not rename or move it.
 */
final readonly class TraceContextStamp implements StampInterface
{
    /**
     * @param array<non-empty-string, string> $carrier
     */
    public function __construct(
        public array $carrier,
    ) {}
}
