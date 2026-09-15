<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushPolicy;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SignalFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TelemetryFlusherTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();
    }

    /**
     * @param non-empty-string $name
     * @param \Closure(): bool $flush
     */
    private function signal(
        string $name,
        \Closure $flush,
        FrozenClock $clock,
        ExportFailureReporter $reporter,
    ): SignalFlusher {
        $provider = $this->createMock(TracerProviderInterface::class);
        $provider->expects(self::never())->method('getTracer');
        $provider->method('forceFlush')->willReturnCallback($flush);
        $provider->method('shutdown')->willReturnCallback($flush);

        return new SignalFlusher($provider, new FlushPolicy($name, 1, $clock), $reporter, 100, $clock);
    }

    #[Test]
    public function allSignalsShareOneDeadlineAndSkippedSignalsRemainEligible(): void
    {
        $clock = new FrozenClock();
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $budget = new FlushBudget(100, $clock);
        $calls = [];
        $state = new \ArrayObject(['slow' => true]);
        $traces = $this->signal(
            'traces',
            static function () use (&$calls, $state, $clock): bool {
                $calls[] = 'traces';
                if ($state['slow']) {
                    $clock->advanceSeconds(0.1);
                    $state['slow'] = false;
                }

                return true;
            },
            $clock,
            $reporter,
        );
        $logs = $this->signal(
            'logs',
            static function () use (&$calls): bool {
                $calls[] = 'logs';

                return true;
            },
            $clock,
            $reporter,
        );
        $metrics = $this->signal(
            'metrics',
            static function () use (&$calls): bool {
                $calls[] = 'metrics';

                return true;
            },
            $clock,
            $reporter,
        );
        $flusher = Flushers::coordinating($traces, $logs, $metrics, $budget, $reporter);

        $flusher->atBoundary();
        $flusher->atBoundary();
        self::assertSame(['traces'], $calls);
        self::assertFalse($budget->active());
        self::assertSame(1, $reporter->total());

        $flusher->atBoundary();
        self::assertSame(['traces', 'traces', 'logs', 'metrics'], $calls);
    }

    #[Test]
    public function falseAndThrownFailuresBackOffWithoutPreventingOtherSignals(): void
    {
        $clock = new FrozenClock();
        $logger = new RecordingLogger();
        $reporter = new ExportFailureReporter($logger);
        $calls = [];
        $traces = $this->signal(
            'traces',
            static function () use (&$calls): bool {
                $calls[] = 'traces';

                return false;
            },
            $clock,
            $reporter,
        );
        $logs = $this->signal(
            'logs',
            /** @throws \RuntimeException */ static function () use (&$calls): bool {
                $calls[] = 'logs';
                throw new \RuntimeException('offline');
            },
            $clock,
            $reporter,
        );
        $metrics = $this->signal(
            'metrics',
            static function () use (&$calls): bool {
                $calls[] = 'metrics';

                return true;
            },
            $clock,
            $reporter,
        );
        $budget = new FlushBudget(100, $clock);
        $flusher = Flushers::coordinating($traces, $logs, $metrics, $budget, $reporter);
        $flusher->atBoundary();
        $flusher->atBoundary();
        self::assertSame(['traces', 'logs', 'metrics'], $calls);
        self::assertSame(2, $reporter->total());
        $clock->advanceSeconds(0.01);
        $flusher->atBoundary();
        self::assertSame(['traces', 'logs', 'metrics', 'metrics'], $calls);
        $clock->advanceSeconds(0.1);
        $flusher->atBoundary();
        self::assertSame(['traces', 'logs', 'metrics', 'metrics', 'traces', 'logs', 'metrics'], $calls);
        self::assertFalse($budget->active());
    }

    #[Test]
    public function shutdownMakesAFinalAttemptDespiteCooldown(): void
    {
        $clock = new FrozenClock();
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $calls = new \ArrayObject();
        $signal = $this->signal(
            'traces',
            static function () use (&$calls): bool {
                $calls->append(1);

                return false;
            },
            $clock,
            $reporter,
        );
        $signal->atBoundary();
        $signal->atBoundary();
        $signal->atBoundary();
        self::assertCount(1, $calls);
        $signal->atShutdown();
        self::assertCount(2, $calls);
    }

    #[Test]
    public function exporterFailuresCountEvenWhenTheProviderReturnsTrue(): void
    {
        $clock = new FrozenClock();
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $calls = new \ArrayObject();
        $signal = $this->signal(
            'traces',
            static function () use (&$calls, $reporter): bool {
                $calls->append(1);
                $reporter->record('rejected export', new \RuntimeException('offline'));

                return true;
            },
            $clock,
            $reporter,
        );
        $signal->atBoundary();
        $signal->atBoundary();

        $clock->advanceSeconds(0.01);
        $signal->atBoundary();
        self::assertCount(1, $calls);
    }

    #[Test]
    public function nestedFlushDoesNotReplaceTheDeadline(): void
    {
        $clock = new FrozenClock();
        $budget = new FlushBudget(100, $clock);
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $provider = $this->createMock(TracerProviderInterface::class);
        $provider->expects(self::never())->method('forceFlush');
        $signal = new SignalFlusher($provider, new FlushPolicy('traces'), $reporter);
        $flusher = Flushers::coordinating($signal, $signal, $signal, $budget, $reporter);
        $budget->begin();
        $clock->advanceSeconds(0.05);
        $flusher->atShutdown();
        self::assertSame(0.05, $budget->remainingSeconds());
        $budget->end();
    }

    #[Test]
    public function shutdownStopsBeforeStartingAnotherSignalAfterTheDeadline(): void
    {
        $clock = new FrozenClock();
        $budget = new FlushBudget(100, $clock);
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $provider = $this->createMock(TracerProviderInterface::class);
        $provider
            ->expects(self::once())
            ->method('shutdown')
            ->willReturnCallback(static function () use ($clock): bool {
                $clock->advanceSeconds(0.1);

                return true;
            });
        $signal = new SignalFlusher($provider, new FlushPolicy('traces'), $reporter);
        Flushers::coordinating($signal, $signal, $signal, $budget, $reporter)->atShutdown();
        self::assertFalse($budget->active());
        self::assertSame(1, $reporter->total());
    }
}
