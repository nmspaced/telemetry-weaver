<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;

/**
 * @internal The callback can enrich an execution, but only run() owns its completion.
 */
final readonly class CallbackContext implements OperationContext
{
    public function __construct(
        private OperationContext $execution,
    ) {}

    #[\Override]
    public function span(): Span
    {
        return $this->execution->span();
    }

    #[\Override]
    public function baggage(): array
    {
        return $this->execution->baggage();
    }

    #[\Override]
    public function metricAttributes(array $attributes): void
    {
        $this->execution->metricAttributes($attributes);
    }

    #[\Override]
    public function fail(string $type): void
    {
        $this->execution->fail($type);
    }
}
