<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use Nmspaced\TelemetryWeaver\Tests\Support\FrameworkInstrumentationTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * `ConsoleTelemetrySubscriber` behaviour: exit-code/error recording, nested command and
 * excluded-worker command handling, process attribute conventions, and the flush boundary
 * that must see the finished command span.
 */
final class ConsoleInstrumentationTest extends FrameworkInstrumentationTestCase
{
    /** The flush schedule is process state: a boundary in this test must not inherit another test's interval. */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        FlushPolicy::resetProcessState();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();
        parent::tearDown();
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function consoleUsesFinalExitCodeAndRestoresContext(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));

        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $application->addCommand(new Command('app:test')->setCode(static fn(): int => 7));
        self::assertSame(7, $application->run(new ArrayInput(['command' => 'app:test']), new BufferedOutput()));
        self::assertSame('console app:test', $this->span()->getName());
        self::assertSame('7', $this->span()->getAttributes()->get('error.type'));
        self::assertNull($this->telemetry->activeTrace());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function consoleErrorRecoveryDoesNotMarkASuccessfulCommandAsFailed(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));
        $dispatcher->addListener(ConsoleEvents::ERROR, static function (ConsoleErrorEvent $event): void {
            $event->setExitCode(0);
        });

        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $application->addCommand(new Command('app:test')->setCode(
            /** @throws \RuntimeException */
            static function (): int {
                throw new \RuntimeException('recovered');
            },
        ));
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:test']), new BufferedOutput()));
        self::assertSame(StatusCode::STATUS_UNSET, $this->span()->getStatus()->getCode());
        self::assertCount(0, $this->span()->getEvents());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function disabledCommandsAndWorkersDoNotStartAConsoleSpan(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));
        $dispatcher->addListener(ConsoleEvents::COMMAND, static fn(ConsoleCommandEvent $event): bool => $event->disableCommand());

        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $application->addCommand(new Command('app:test')->setCode(static fn(): int => 0));
        $application->run(new ArrayInput(['command' => 'app:test']), new BufferedOutput());
        self::assertSame([], $this->telemetry->spans());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function nestedConsoleCommandsFinishInsideTheirParentAndWorkerCommandsAreExcluded(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter, ['messenger:consume']);
        $dispatcher->addSubscriber($subscriber);
        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand(new Command('app:child')->setCode(static fn(): int => 0));
        $app->addCommand(new Command('app:parent')->setCode(
            /** @throws \Throwable */
            static fn(): int => $app->run(new ArrayInput([
                'command' => 'app:child',
            ]), new BufferedOutput()),
        ));
        $app->addCommand(new Command('messenger:consume')->setCode(static fn(): int => 0));
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:parent']), new BufferedOutput()));
        self::assertCount(2, $this->telemetry->spans());
        self::assertSame($this->span(1)->getContext()->getSpanId(), $this->span(0)->getParentContext()->getSpanId());
        $app->run(new ArrayInput(['command' => 'messenger:consume']), new BufferedOutput());
        self::assertCount(2, $this->telemetry->spans());
        $input = new ArrayInput([]);
        $subscriber->onCommand(new ConsoleCommandEvent(new Command('app:unfinished'), $input, new BufferedOutput()));
        $subscriber->reset();
        self::assertNull($this->telemetry->activeTrace());
    }

    /**
     * The nested command must not flush; the outer one must, once its span is finished.
     *
     * A console boundary flushes rather than shuts down: the pipeline of a CLI process is
     * sealed by `ExportGate` when the PHP execution ends, so that a second top-level
     * `Application::run()` still has providers to export through.
     *
     * @throws \Throwable
     */
    #[Test]
    public function consoleFlushesBothProvidersAfterTheCommandSpanFinishes(): void
    {
        $meters = $this->createMock(MeterProviderInterface::class);
        $tracers = $this->createMock(TracerProviderInterface::class);
        $meters->expects(self::never())->method('shutdown');
        $tracers->expects(self::never())->method('shutdown');
        $meters
            ->expects(self::once())
            ->method('forceFlush')
            ->willReturnCallback(function (): bool {
                self::assertCount(2, $this->telemetry->spans());

                return true;
            });
        $tracers
            ->expects(self::once())
            ->method('forceFlush')
            ->willReturnCallback(function (): bool {
                self::assertCount(2, $this->telemetry->spans());

                return true;
            });
        $failures = new ExportFailureReporter(new NullLogger());
        $flush = new ConsoleFlushSubscriber(
            Flushers::coordinating(
                new SignalFlusher($tracers, FlushPolicy::onSdkSchedule('traces'), $failures),
                new SignalFlusher(new NoopLoggerProvider(), FlushPolicy::onSdkSchedule('logs'), $failures),
                new SignalFlusher($meters, FlushPolicy::onSdkSchedule('metrics'), $failures),
                new FlushBudget(),
                $failures,
            ),
            SymfonyRuntimeProfile::fromKernel(0, false),
        );
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($flush);
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));

        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand(new Command('app:child')->setCode(static fn(): int => 0));
        $app->addCommand(new Command('app:test')->setCode(
            /** @throws \Throwable */
            static fn(): int => $app->run(new ArrayInput(['command' => 'app:child']), new BufferedOutput()),
        ));
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:test']), new BufferedOutput()));
    }

    /**
     * A controller running a console Application on the kernel's dispatcher used to close
     * the export gate and shut the SDK providers down from inside a live HTTP request. In
     * a worker keeping its kernel that lost the telemetry of every later request, since
     * nothing reopens a sealed pipeline; in FPM it lost the rest of the current one.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aCommandRunInsideARequestDoesNotFinalizeTheHttpPipeline(): void
    {
        $meters = $this->createMock(MeterProviderInterface::class);
        $tracers = $this->createMock(TracerProviderInterface::class);
        // Exactly once, and the assertions below fix when: at the request's own boundary,
        // not at the command's. A boundary flush would be no better than a shutdown here —
        // it would move a blocking export onto the path of a request still being served.
        $meters->expects(self::once())->method('shutdown')->willReturn(true);
        $tracers->expects(self::once())->method('shutdown')->willReturn(true);
        $meters->expects(self::never())->method('forceFlush');
        $tracers->expects(self::never())->method('forceFlush');

        $failures = new ExportFailureReporter(new NullLogger());
        $budget = new FlushBudget();
        $providers = new ProviderRegistry(ExportGate::forBudget($budget), $failures);
        foreach ([
            new SignalFlusher($tracers, FlushPolicy::onSdkSchedule('traces'), $failures),
            new SignalFlusher(new NoopLoggerProvider(), FlushPolicy::onSdkSchedule('logs'), $failures),
            new SignalFlusher($meters, FlushPolicy::onSdkSchedule('metrics'), $failures),
        ] as $signal) {
            $providers->add($signal);
        }

        // The flusher gets the request profile so that this test leaves no process-exit
        // flush behind for PHPUnit's own shutdown; the subscriber gets the worker profile,
        // which is where sealing here used to cost every later request its telemetry.
        $flusher = new TelemetryFlusher($providers, $budget, $failures, SymfonyRuntimeProfile::fromKernel(0, true));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleFlushSubscriber($flusher, SymfonyRuntimeProfile::fromKernel(1, true)));
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));

        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand(new Command('app:test')->setCode(static fn(): int => 0));

        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:test']), new BufferedOutput()));
        self::assertFalse($providers->isClosed(), 'the command sealed a pipeline the request still owns');

        // What the request's own terminate does next still reaches live providers.
        $flusher->atShutdown();
        self::assertTrue($providers->isClosed());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function aFailedCommandRecordsItsExitCodeAsTheErrorTypeOnBothSignals(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));

        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand(new Command('app:ok')->setCode(static fn(): int => 0));
        $app->addCommand(new Command('app:fails')->setCode(static fn(): int => 3));

        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:ok']), new BufferedOutput()));
        self::assertSame(3, $app->run(new ArrayInput(['command' => 'app:fails']), new BufferedOutput()));

        $points = self::histogramPoints($this->telemetry->measurements(), 'console.command.duration');
        self::assertSame(
            [
                ['console.command.name' => 'app:ok'],
                ['console.command.name' => 'app:fails', 'error.type' => '3'],
            ],
            \array_map(
                /** @param array{attributes: array<array-key, mixed>} $point */
                static fn(array $point): mixed => $point['attributes'],
                $points,
            ),
        );
        self::assertSame(0, $this->span(0)->getAttributes()->get('process.exit.code'));
        self::assertSame(3, $this->span(1)->getAttributes()->get('process.exit.code'));
    }

    /**
     * The CLI conventions require the executable, the pid and the exit code on the span
     * of a program run. The pid stays out of the histogram: one value per process is one
     * series per process, and the resource already says which process reported.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aCommandSpanCarriesTheProcessAttributesOfTheCliConventions(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleTelemetrySubscriber($this->telemetry, $this->reporter));

        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand(new Command('app:ok')->setCode(static fn(): int => 0));

        $app->run(new ArrayInput(['command' => 'app:ok']), new BufferedOutput());

        $span = $this->span();
        self::assertSame(\basename(\PHP_BINARY), $span->getAttributes()->get('process.executable.name'));
        self::assertSame(\getmypid(), $span->getAttributes()->get('process.pid'));
        self::assertSame(0, $span->getAttributes()->get('process.exit.code'));
        self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        self::assertNull($span->getAttributes()->get('process.command_args'), 'arguments are where secrets live');

        $points = self::histogramPoints($this->telemetry->measurements(), 'console.command.duration');
        $point = $points[0] ?? self::fail('missing console.command.duration point');
        self::assertSame(['console.command.name' => 'app:ok'], $point['attributes']);
    }
}
