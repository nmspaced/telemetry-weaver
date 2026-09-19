<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\Context\ContextInterface;

/**
 * An OpenTelemetry context, carried as an opaque correlation token.
 *
 * The property is public because the only reader is {@see OtelDurationRecorder}, which
 * lives beside it in the adapter; a getter would suggest the value travels further than
 * it does.
 *
 * @internal
 */
final readonly class OtelTraceCorrelation implements TraceCorrelation
{
    public function __construct(
        public ContextInterface $context,
    ) {}
}
