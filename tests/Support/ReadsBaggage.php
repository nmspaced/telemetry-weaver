<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;

/**
 * Reads baggage back from an operation and from what a propagator would send.
 *
 * @internal
 *
 * @phpstan-require-extends TelemetryTestCase
 */
trait ReadsBaggage
{
    /** @return array<non-empty-string, string> */
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
