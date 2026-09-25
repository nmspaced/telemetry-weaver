<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OperationParent;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\ReadsBaggage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Baggage belongs to the context, not to the span, so it has to work where no span is
 * recorded: a boundary with its span suppressed, and an application with tracing off.
 */
#[CoversClass(ContextOnlyOpener::class)]
#[CoversClass(OperationParent::class)]
final class BaggageWithoutSpanTest extends PublicTelemetryTestCase
{
    use ReadsBaggage;

    /**
     * A suppressed span is not a suppressed context. The entries live in a context the
     * operation activates for itself, so they are carried exactly as with a span.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anOperationWithoutASpanStillCarriesItsBaggage(): void
    {
        $telemetry = $this->telemetry();

        $carrier = [];
        $seen = $telemetry
            ->boundary('suppressed')
            ->withoutSpan()
            ->baggage(['tenant.id' => 'acme'])
            ->run(
                /** @return array<non-empty-string, string> */
                function (OperationContext $context) use (&$carrier): array {
                    $carrier = $this->injectedBaggage();

                    return self::baggageOf($context);
                },
            );

        self::assertSame(['tenant.id' => 'acme'], $seen);
        self::assertSame(['baggage' => 'tenant.id=acme'], $carrier);
        self::assertSame([], $telemetry->operation('after')->run(self::baggageOf(...)), 'restored afterwards');
    }

    /**
     * `traces.enabled: false` switches off spans, not baggage, which W3C defines
     * independently of tracing. Everything the traced tests above check holds here too:
     * adding, inheriting, sending out, and restoring the caller's context afterwards.
     *
     * @throws \Throwable
     */
    #[Test]
    public function baggageWorksTheSameWithTracingOff(): void
    {
        $telemetry = $this->telemetry(traces: false);
        $inner = [];
        $carrier = [];

        $outer = $telemetry
            ->operation('outer')
            ->baggage(['tenant.id' => 'acme'])
            ->run(
                /**
                 * @return array<non-empty-string, string>
                 *
                 * @throws \Throwable
                 */
                function (OperationContext $context) use ($telemetry, &$inner, &$carrier): array {
                    $inner = $telemetry
                        ->operation('inner')
                        ->baggage(['cohort' => 'beta'])
                        ->run(
                            /** @return array<non-empty-string, string> */
                            function (OperationContext $context) use (&$carrier): array {
                                $carrier = $this->injectedBaggage();

                                return self::baggageOf($context);
                            },
                        );

                    return self::baggageOf($context);
                },
            );

        self::assertSame(['tenant.id' => 'acme', 'cohort' => 'beta'], $inner);
        self::assertSame(['baggage' => 'tenant.id=acme,cohort=beta'], $carrier);
        self::assertSame(['tenant.id' => 'acme'], $outer, 'the caller does not inherit what its callee added');
        self::assertSame(
            [],
            $telemetry->operation('after')->run(self::baggageOf(...)),
            'nothing leaks into the next one',
        );
        self::assertNull($this->contextStorage->scope(), 'every activation was released');
        self::assertSame([], $this->exporter->getSpans(), 'and no span was recorded');
    }

    /**
     * The saving the old no-op made, kept: an operation that changes nothing about the
     * context does not activate one.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anOperationWithoutSpanOrBaggageActivatesNothing(): void
    {
        $scope = $this
            ->telemetry(traces: false)
            ->operation('plain')
            ->run(fn(): mixed => $this->contextStorage->scope());

        self::assertNull($scope);
    }
}
