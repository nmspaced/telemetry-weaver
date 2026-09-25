<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\Context\ContextInterface;

/**
 * An OpenTelemetry context carried as an opaque correlation token.
 *
 * @internal
 */
final readonly class OtelTraceCorrelation implements TraceCorrelation
{
    public function __construct(
        public ContextInterface $context,
    ) {}
}
