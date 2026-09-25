<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Internal\Operation\OperationPlan;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\ReadsBaggage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Values that travel with the trace rather than with the span.
 *
 * The distinction these tests are protecting is where an entry stops. An attribute stops
 * at the span it was set on; baggage reaches every service the operation calls, so an
 * entry that outlived its operation — or leaked back into the caller — is a value being
 * sent to other people's systems after the code that asked for it has returned.
 */
#[CoversClass(OperationPlan::class)]
#[CoversClass(OtelBaggageReader::class)]
final class BaggageTest extends PublicTelemetryTestCase
{
    use ReadsBaggage;

    /** @throws \Throwable */
    #[Test]
    public function anOperationSeesWhatItAdded(): void
    {
        $seen = $this
            ->telemetry()
            ->operation('checkout')
            ->baggage(['tenant.id' => 'acme'])
            ->run(self::baggageOf(...));

        self::assertSame(['tenant.id' => 'acme'], $seen);
    }

    /**
     * The point of baggage: it is in the context, so every propagator asked to inject
     * inside the operation carries it out of the process.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theEntriesReachAnOutgoingCarrier(): void
    {
        $carrier = $this
            ->telemetry()
            ->operation('checkout')
            ->baggage(['tenant.id' => 'acme'])
            ->run($this->injectedBaggage(...));

        self::assertSame(['baggage' => 'tenant.id=acme'], $carrier);
    }

    /** @throws \Throwable */
    #[Test]
    public function aNestedOperationInheritsAndCanAddWithoutTouchingItsCaller(): void
    {
        $telemetry = $this->telemetry();
        $inner = [];

        $outer = $telemetry
            ->operation('outer')
            ->baggage(['tenant.id' => 'acme'])
            ->run(
                /**
                 * @return array<non-empty-string, string>
                 *
                 * @throws \Throwable
                 */
                static function (OperationContext $context) use ($telemetry, &$inner): array {
                    $inner = $telemetry
                        ->operation('inner')
                        ->baggage(['cohort' => 'beta'])
                        ->run(self::baggageOf(...));

                    return self::baggageOf($context);
                },
            );

        self::assertSame(['tenant.id' => 'acme', 'cohort' => 'beta'], $inner);
        self::assertSame(['tenant.id' => 'acme'], $outer, 'the caller does not inherit what its callee added');
    }

    /** @throws \Throwable */
    #[Test]
    public function aRepeatedKeyIsReplacedForTheCalleeOnly(): void
    {
        $telemetry = $this->telemetry();
        $inner = [];

        $outer = $telemetry
            ->operation('outer')
            ->baggage(['tenant.id' => 'acme'])
            ->run(
                /**
                 * @return array<non-empty-string, string>
                 *
                 * @throws \Throwable
                 */
                static function (OperationContext $context) use ($telemetry, &$inner): array {
                    $inner = $telemetry
                        ->operation('inner')
                        ->baggage(['tenant.id' => 'globex'])
                        ->run(self::baggageOf(...));

                    return self::baggageOf($context);
                },
            );

        self::assertSame(['tenant.id' => 'globex'], $inner);
        self::assertSame(['tenant.id' => 'acme'], $outer);
    }

    /** @throws \Throwable */
    #[Test]
    public function nothingSurvivesTheOperationThatAddedIt(): void
    {
        $telemetry = $this->telemetry();
        $telemetry
            ->operation('checkout')
            ->baggage(['tenant.id' => 'acme'])
            ->run(static fn(): null => null);

        $after = $telemetry->operation('after')->run(self::baggageOf(...));

        self::assertSame([], $after);
    }

    /** @throws \Throwable */
    #[Test]
    public function anEmptyDeclarationChangesNothing(): void
    {
        $plan = $this->telemetry()->operation('checkout');

        self::assertSame($plan, $plan->baggage([]));
    }
}
