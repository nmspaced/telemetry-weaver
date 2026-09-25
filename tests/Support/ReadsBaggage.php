<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;

/**
 * The two ways baggage is observed: what an operation reads back, and what a propagator
 * sends out from the current context.
 *
 * @internal
 *
 * @phpstan-require-extends TelemetryTestCase
 */
trait ReadsBaggage
{
    /**
     * Named rather than inline so the map's own type survives into the assertions.
     *
     * @return array<non-empty-string, string>
     */
    protected static function baggageOf(OperationContext $context): array
    {
        return $context->baggage();
    }

    /** @return array<array-key, mixed> */
    protected function injectedBaggage(): array
    {
        /** @var mixed $carrier */
        $carrier = [];
        BaggagePropagator::getInstance()->inject($carrier, null, $this->contextStorage->current());

        return \is_array($carrier) ? $carrier : [];
    }
}
