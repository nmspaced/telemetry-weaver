<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Internal\Operation\OperationPlan;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
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
    /** @throws \Throwable */
    #[Test]
    public function anOperationSeesWhatItAdded(): void
    {
        $seen = $this
            ->telemetry()
            ->operation('checkout')
            ->baggage(['tenant.id' => 'acme'])
            ->run(self::entries(...));

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
            ->run($this->injected(...));

        self::assertSame(['baggage' => 'tenant.id=acme'], $carrier);
    }

    /** @return array<array-key, mixed> */
    private function injected(): array
    {
        /** @var mixed $carrier */
        $carrier = [];
        BaggagePropagator::getInstance()->inject($carrier, null, $this->contextStorage->current());

        return \is_array($carrier) ? $carrier : [];
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
                        ->run(self::entries(...));

                    return self::entries($context);
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
                        ->run(self::entries(...));

                    return self::entries($context);
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

        $after = $telemetry->operation('after')->run(self::entries(...));

        self::assertSame([], $after);
    }

    /**
     * The documented limitation. Entries live in the context activation the span owns, and
     * a suppressed operation has none — so `withoutSpan()` silently carries nothing rather
     * than opening a second activation on every Doctrine query to hold entries almost
     * nobody sets.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anOperationWithoutASpanCarriesNoBaggage(): void
    {
        $seen = $this
            ->telemetry()
            ->boundary('suppressed')
            ->withoutSpan()
            ->baggage(['tenant.id' => 'acme'])
            ->run(self::entries(...));

        self::assertSame([], $seen);
    }

    /**
     * Named rather than inline so the map's own type survives into the assertions.
     *
     * @return array<non-empty-string, string>
     */
    private static function entries(OperationContext $context): array
    {
        return $context->baggage();
    }

    /** @throws \Throwable */
    #[Test]
    public function anEmptyDeclarationChangesNothing(): void
    {
        $plan = $this->telemetry()->operation('checkout');

        self::assertSame($plan, $plan->baggage([]));
    }
}
