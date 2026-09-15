<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fiber isolation and process-lifetime leak safety: the shared facade must not mix
 * interleaved fiber contexts, and a thousand repeated executions must release every
 * captured closure and every live SDK span. This is where the worker-mode "no memory
 * leaks" principle is exercised directly.
 */
final class PublicTelemetryConcurrencyTest extends PublicTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function sharedFacadeDoesNotMixInterleavedFiberContexts(): void
    {
        $this->useFiberBoundStorage();
        $telemetry = $this->telemetry();
        $run =
            /**
             * @param non-empty-string $name
             *
             * @throws \Throwable
             */
            static function (string $name) use ($telemetry): void {
                $telemetry->trace(
                    $name,
                    /** @throws \Throwable */
                    static function (Span $span) use ($telemetry): void {
                        $id = $span->context()->getSpanId();
                        \Fiber::suspend();
                        self::assertSame($id, $telemetry->currentSpan()->context()->getSpanId());
                    },
                );
                self::assertFalse($telemetry->currentSpan()->context()->isValid());
            };
        $first = new \Fiber(
            /** @throws \Throwable */
            static function () use ($run): void {
                $run('first');
            },
        );
        $second = new \Fiber(
            /** @throws \Throwable */
            static function () use ($run): void {
                $run('second');
            },
        );
        $first->start();
        $second->start();
        $first->resume();
        $second->resume();
        self::assertSame(['first', 'second'], $this->exportedNames());
        $this->assertNoReports();
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function repeatedExecutionsReleaseCapturedWorkAndLiveSdkSpans(): void
    {
        $telemetry = $this->telemetry();
        $duration = $this->duration($telemetry);
        $live = new \WeakMap();
        for ($index = 0; $index < 1000; ++$index) {
            $payload = new \stdClass();
            $payload->value = 'request';
            $live[$payload] = true;
            $telemetry
                ->operation('work')
                ->duration($duration)
                ->run(static function (OperationContext $context) use ($payload, $live): void {
                    $live[OtelSpan::getCurrent()] = true;
                    $context->span()->attribute('payload', (string) $payload->value);
                });
            unset($payload);
            // An in-memory exporter deliberately accumulates immutable data; it is not an execution leak.
            $this->exporter->getStorage()->exchangeArray([]);
            $abandoned = $telemetry->operation('interrupted')->duration($duration)->start();
            $live[OtelSpan::getCurrent()] = true;
            $abandoned->abandon();
            $this->exporter->getStorage()->exchangeArray([]);
        }

        self::assertCount(0, $live);
        self::assertNull($this->contextStorage->scope());
        self::assertSame(1000, $this->metricPoint()->count);
    }
}
