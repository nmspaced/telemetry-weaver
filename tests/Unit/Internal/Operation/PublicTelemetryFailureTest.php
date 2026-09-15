<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Error and `fail()` outcome semantics: an unhandled exception, the domain error type
 * winning over a merely-recorded exception event, `fail()` marking both signals without
 * an exception, the last explicit type winning over a later exception, and `fail()` after
 * completion changing nothing. Core lifecycle lives in {@see PublicTelemetryTest}.
 */
final class PublicTelemetryFailureTest extends PublicTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function unhandledExceptionKeepsIdentityAndOneMatchingErrorOutcome(): void
    {
        $error = new \RuntimeException('business failure');
        $calls = 0;
        $telemetry = $this->telemetry();
        try {
            $telemetry
                ->operation('checkout')
                ->duration($this->duration($telemetry), ['channel' => 'web'])
                ->run(
                    /** @throws \RuntimeException */
                    function (OperationContext $context) use ($error, &$calls): never {
                        ++$calls;
                        $context->span()->attribute('order.id', '42');
                        $this->clock->advanceSeconds(0.25);
                        throw $error;
                    },
                );
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertSame(1, $calls);
        $span = $this->exportedSpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertCount(1, $span->getEvents());
        self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
        $point = $this->metricPoint();
        self::assertSame(0.25, $point->sum);
        self::assertSame(1, $point->count);
        self::assertSame(\RuntimeException::class, $point->attributes->get('error.type'));
        self::assertNull($point->attributes->get('order.id'));
        self::assertNull($this->contextStorage->scope());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function domainErrorTypeWinsAndAnExceptionEventAloneDoesNotMarkFailure(): void
    {
        $telemetry = $this->telemetry();
        $operation = $telemetry
            ->operation('domain')
            ->attributes(['error.type' => 'out_of_stock'])
            ->duration($this->duration($telemetry), ['error.type' => 'other'])
            ->start();
        $operation->finish(new \RuntimeException());
        self::assertSame('out_of_stock', $this->exportedSpan()->getAttributes()->get('error.type'));
        self::assertSame('out_of_stock', $this->metricPoint()->attributes->get('error.type'));
        $telemetry->trace('handled', static function (Span $span): void {
            $span->recordException(new \RuntimeException('handled'));
        });
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan(1)->getStatus()->getCode());
    }

    /**
     * A logical failure — a declined payment, an empty stock — is not an exception, and
     * it has to mark both signals at once. Before `fail()` a caller had `span()->fail()`,
     * which left the duration recorded as a success, and nothing at all in metric-only
     * mode, where the span is inert.
     *
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('signals')]
    public function failMarksBothSignalsWithoutAnException(bool $traces, bool $metrics): void
    {
        $telemetry = $this->telemetry($traces, $metrics);

        $result = $telemetry
            ->operation('checkout')
            ->duration($this->duration($telemetry))
            ->run(static function (OperationContext $context): string {
                $context->fail('payment.declined');

                return 'declined';
            });

        self::assertSame('declined', $result, 'fail() changes the telemetry, not the outcome');

        if ($traces) {
            $span = $this->exportedSpan();
            self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            self::assertSame('payment.declined', $span->getAttributes()->get('error.type'));
            self::assertSame([], $span->getEvents(), 'no exception is manufactured');
        }

        if ($metrics) {
            self::assertSame('payment.declined', $this->metricPoint()->attributes->get('error.type'));
        }

        $this->assertNoReports();
    }

    /**
     * The explicit type is what the caller knows about the failure; an exception thrown
     * afterwards is recorded as an event but does not overwrite that type.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theLastExplicitTypeWinsOverALaterException(): void
    {
        $telemetry = $this->telemetry();

        try {
            $telemetry
                ->operation('checkout')
                ->duration($this->duration($telemetry))
                ->run(
                    /** @throws \RuntimeException */
                    static function (OperationContext $context): never {
                        $context->fail('payment.pending');
                        $context->fail('payment.declined');

                        throw new \RuntimeException('gateway said no');
                    },
                );
        } catch (\RuntimeException $runtimeException) {
            // The application exception escapes, as always.
            self::assertSame('gateway said no', $runtimeException->getMessage());
        }

        $span = $this->exportedSpan();
        self::assertSame('payment.declined', $span->getAttributes()->get('error.type'));
        self::assertCount(1, $span->getEvents(), 'the exception is still recorded');
        self::assertSame('payment.declined', $this->metricPoint()->attributes->get('error.type'));
    }

    #[Test]
    public function failAfterCompletionChangesNothing(): void
    {
        $telemetry = $this->telemetry();
        $finished = $telemetry->operation('finished')->duration($this->duration($telemetry))->start();
        $finished->finish();
        $finished->fail('too.late');

        $abandoned = $telemetry->operation('abandoned')->start();
        $abandoned->abandon();
        $abandoned->fail('too.late');

        // An abandoned span is still ended — only its duration is discarded.
        self::assertCount(2, $this->exported());
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan()->getStatus()->getCode());
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan(1)->getStatus()->getCode());
        self::assertNull($this->exportedSpan()->getAttributes()->get('error.type'));
        self::assertNull($this->metricPoint()->attributes->get('error.type'));
        $this->assertNoReports();
    }
}
