<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessagingSystem;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerWorkerSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableMessageBusMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableSendersLocator;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;

/**
 * A real bus, middleware chain and Worker over an in-memory transport, so tests see the order in
 * which Messenger fires its events.
 *
 * @internal
 */
abstract class MessengerTelemetryTestCase extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    protected InMemoryExporter $spans;

    protected TracerProvider $tracers;

    protected InMemoryMetricExporter $metrics;

    protected ExportingReader $reader;

    protected MeterProviderInterface $meters;

    protected RecordingLogger $logger;

    protected InMemoryTransport $transport;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousStorage = Context::storage();
        Context::setStorage(new ContextStorage());

        $this->spans = new InMemoryExporter();
        $this->tracers = new TracerProvider(new SimpleSpanProcessor($this->spans));

        $this->metrics = new InMemoryMetricExporter();
        $this->reader = new ExportingReader($this->metrics);
        $this->meters = MeterProvider::builder()->addReader($this->reader)->build();

        $this->logger = new RecordingLogger();
        $this->transport = new InMemoryTransport();
    }

    #[\Override]
    protected function tearDown(): void
    {
        while (($scope = Context::storage()->scope()) !== null) {
            $scope->detach();
        }

        $this->meters->shutdown();
        $this->tracers->shutdown();
        Context::setStorage($this->previousStorage);
    }

    protected function telemetry(): MessengerTelemetry
    {
        $reporter = new InstrumentationFailureReporter($this->logger);

        return new MessengerTelemetry(TelemetryFactory::create(
            $this->meters->getMeter('test'),
            new SpanOpener($this->tracers->getTracer('test'), Context::storage(), $reporter),
            $reporter,
            new FrozenClock(),
        ));
    }

    protected function consumption(
        MessengerTelemetry $telemetry,
        ?MessagingSystem $systems = null,
        ?Propagation $propagation = null,
    ): MessengerConsumption {
        $reporter = new InstrumentationFailureReporter($this->logger);

        return new MessengerConsumption(
            $telemetry,
            $propagation ?? new OtelPropagation(TraceContextPropagator::getInstance(), Context::storage(), $reporter),
            $reporter,
            $systems ?? new MessagingSystem(),
        );
    }

    protected function syncBus(MessengerTelemetry $telemetry, EventDispatcher $dispatcher): MessageBus
    {
        return new MessageBus([
            new TraceableMessageBusMiddleware($telemetry, 'messenger.bus.commands', $this->consumption($telemetry)),
            new SendMessageMiddleware(new SendersLocator([], new Container([])), $dispatcher),
            new HandleMessageMiddleware(new HandlersLocator([
                SampleMessage::class => [static fn(SampleMessage $message): string => $message->name],
            ])),
        ]);
    }

    /** @param array<string, InMemoryTransport> $senders */
    protected function asyncBus(
        MessengerTelemetry $telemetry,
        EventDispatcher $dispatcher,
        bool $fail = false,
        ?array $senders = null,
        ?MessagingSystem $systems = null,
    ): MessageBus {
        $reporter = new InstrumentationFailureReporter($this->logger);
        $propagator = TraceContextPropagator::getInstance();
        $systems ??= new MessagingSystem();

        $dispatcher->addSubscriber(new MessengerWorkerSubscriber($telemetry, $reporter, $systems));

        $senders ??= ['async' => $this->transport];

        $handler = $fail
            ? /** @throws \RuntimeException */
            static function (SampleMessage $_message): never {
                throw new \RuntimeException('handler exploded');
            }
            : static fn(SampleMessage $message): string => $message->name;

        return new MessageBus([
            new TraceableMessageBusMiddleware(
                $telemetry,
                'messenger.bus.commands',
                $this->consumption($telemetry, $systems),
            ),
            new SendMessageMiddleware(
                new TraceableSendersLocator(
                    new SendersLocator([SampleMessage::class => \array_keys($senders)], new Container($senders)),
                    $telemetry,
                    new OtelPropagation($propagator, Context::storage(), $reporter),
                    $reporter,
                    $systems,
                ),
                $dispatcher,
            ),
            new HandleMessageMiddleware(new HandlersLocator([SampleMessage::class => [$handler]])),
        ]);
    }

    /** Runs the worker for exactly the messages already in the transport. */
    protected function work(MessengerTelemetry $_telemetry, EventDispatcher $dispatcher, MessageBus $bus): void
    {
        $worker = new Worker(['async' => $this->transport], $bus, $dispatcher);

        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if (!$event->isWorkerIdle()) {
                return;
            }

            $event->getWorker()->stop();
        });

        $worker->run(['sleep' => 0]);
    }
}
