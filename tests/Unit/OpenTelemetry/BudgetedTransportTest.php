<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpTransportSettings;
use Nmspaced\TelemetryWeaver\OpenTelemetry\TransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingTransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\StallingTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BudgetedTransportTest extends TestCase
{
    #[Test]
    public function batchesReceiveTheRemainingBudgetAndNeverRetry(): void
    {
        $clock = new FrozenClock();
        $budget = new FlushBudget(1000, $clock);
        $delegate = new RecordingTransportFactory();
        $transport = new TransportFactory(
            $delegate,
            ExportGate::forBudget($budget),
            new OtlpTransportSettings(maxRetries: 3),
        )
            ->create('http://localhost:4318/v1/traces', 'application/json', timeout: 10);
        self::assertSame(3, $delegate->argument('maxRetries'));
        $budget->begin();
        $transport->send('first')->await();
        self::assertSame(1.0, $delegate->argument('timeout', 1));
        self::assertSame(0, $delegate->argument('maxRetries', 1));
        $clock->advanceSeconds(0.75);
        $transport->send('second')->await();
        self::assertSame(0.25, $delegate->argument('timeout', 2));
        $clock->advanceSeconds(0.25);
        try {
            $transport->send('third')->await();
            self::fail('An exhausted budget must reject the batch');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('budget exhausted', $runtimeException->getMessage());
        }

        self::assertCount(3, $delegate->calls);
        $budget->end();
        $transport->send('outside')->await();
        self::assertCount(3, $delegate->calls, 'outside boundaries the original transport is reused');
    }

    #[Test]
    public function aShorterTransportTimeoutIsPreservedAndShutdownCannotBeBypassed(): void
    {
        $budget = new FlushBudget(1000, new FrozenClock());
        $delegate = new RecordingTransportFactory();
        $transport = new TransportFactory($delegate, ExportGate::forBudget($budget))
            ->create('http://localhost:4318/v1/traces', 'application/json', timeout: 0.1);
        $budget->begin();
        $transport->send('first')->await();
        self::assertSame(0.1, $delegate->argument('timeout', 1));
        self::assertTrue($transport->shutdown());
        self::assertFalse($transport->shutdown());
        $this->expectException(\BadMethodCallException::class);
        $transport->send('after shutdown')->await();
    }

    /**
     * Traces and logs to one hung collector, metrics to another. The hung one costs its share
     * once; the logs bound for it are refused without waiting, and metrics still send.
     */
    #[Test]
    public function aHungCollectorCostsItsShareOnceAndOtherCollectorsStillSend(): void
    {
        $clock = new FrozenClock();
        $budget = new FlushBudget(1000, $clock);
        $collectors = new StallingTransportFactory($clock, ['alloy-a' => 10.0]);
        $factory = new TransportFactory($collectors, ExportGate::forBudget($budget));
        $traces = $factory->create('http://alloy-a:4318/v1/traces', 'application/json');
        // Same collector: scheme and host are case-insensitive, credentials are not part of it.
        $logs = $factory->create('HTTP://user:secret@Alloy-A:4318/v1/logs', 'application/json');
        $metrics = $factory->create('http://collector-b:4318/v1/metrics', 'application/json');

        $budget->begin();
        try {
            self::assertFailsWith($traces, 'Operation timed out');
            self::assertEqualsWithDelta(0.5, $clock->now() / 1e9, 1e-6, 'two destinations: half each');

            $message = self::assertFailsWith($logs, 'budget exhausted for http://alloy-a:4318');
            self::assertStringNotContainsString('secret', $message);
            self::assertEqualsWithDelta(0.5, $clock->now() / 1e9, 1e-6, 'the refusal did not wait');

            $metrics->send('metrics')->await();
        } finally {
            $budget->end();
        }

        self::assertSame(
            [
                ['endpoint' => 'http://alloy-a:4318/v1/traces', 'timeout' => 0.5],
                ['endpoint' => 'http://collector-b:4318/v1/metrics', 'timeout' => 0.5],
            ],
            $collectors->sends,
        );
    }

    /** @return string the failure message */
    private static function assertFailsWith(TransportInterface $transport, string $expected): string
    {
        try {
            $transport->send('payload')->await();
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString($expected, $runtimeException->getMessage());

            return $runtimeException->getMessage();
        }

        self::fail('The send must fail with: ' . $expected);
    }
}
