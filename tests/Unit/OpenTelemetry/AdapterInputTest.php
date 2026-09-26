<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\ChannelLoggers;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\InertSpan;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceRelations;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelResponsePropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelTraceCorrelation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingResponsePropagator;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** What the adapters accept from OpenTelemetry and the application, and what they leave out. */
#[CoversClass(OtelBaggageReader::class)]
#[CoversClass(OtelResponsePropagation::class)]
#[CoversClass(SpanOpener::class)]
#[CoversClass(TraceRelations::class)]
#[CoversClass(InertSpan::class)]
#[CoversClass(ExportFailureReporter::class)]
#[CoversClass(ChannelLoggers::class)]
final class AdapterInputTest extends TelemetryTestCase
{
    #[Test]
    public function onlyStringBaggageEntriesUnderTextKeysAreRead(): void
    {
        $context = Baggage::getBuilder()
            ->set('123', 'numeric key')
            ->set('attempt', 5)
            ->set('tenant', 'first')
            ->build()
            ->storeInContext(Context::getRoot());

        self::assertSame(['tenant' => 'first'], new OtelBaggageReader()->of(new OtelTraceCorrelation($context)));
    }

    #[Test]
    public function onlyTextHeadersFromAResponsePropagatorAreKept(): void
    {
        $propagator = new RecordingResponsePropagator(extra: [
            'traceresponse' => '00-abc-01',
            'retry' => 3,
            7 => 'numbered',
        ]);

        $headers = new OtelResponsePropagation($propagator, $this->contextStorage, $this->reporter)->headers();

        self::assertSame(['traceresponse' => '00-abc-01'], $headers);
    }

    #[Test]
    public function aLinkFromAnotherTracingImplementationIsSkipped(): void
    {
        $foreign = new class implements IncomingTrace {
            #[\Override]
            public function isValid(): bool
            {
                return true;
            }
        };

        $this->spans
            ->open('operation', new SpanOptions(relations: TraceRelations::ambient()->linkedTo($foreign)))
            ->finish();

        self::assertSame([], $this->exportedSpan()->getLinks());
        $this->assertNoReports();
    }

    #[Test]
    public function continuingATraceMakesItTheParentWithNothingLinked(): void
    {
        $incoming = new class implements IncomingTrace {
            #[\Override]
            public function isValid(): bool
            {
                return true;
            }
        };

        $relations = TraceRelations::continuing($incoming);

        self::assertSame($incoming, $relations->parent);
        self::assertSame([], $relations->links);
        self::assertFalse($relations->linkActiveSpan);
    }

    #[Test]
    public function anInertSpanHasNothingToReattach(): void
    {
        $span = new InertSpan('operation');

        $span->attach();

        self::assertNull($this->contextStorage->scope());
        self::assertFalse($span->isAbandoned());
    }

    #[Test]
    public function anExportFailureNamesItsCauseToo(): void
    {
        $logger = new RecordingLogger();
        $cause = new \RuntimeException('connection refused');

        new ExportFailureReporter($logger)->record('Export failed', new \RuntimeException('send failed', 0, $cause));

        $context = $logger->records[0]['context'] ?? [];
        self::assertSame('connection refused', $context['previous'] ?? null);
        self::assertSame(\RuntimeException::class, $context['previous_class'] ?? null);
    }

    /** @throws \Throwable */
    #[Test]
    public function aChannelKeepsItsLogger(): void
    {
        $provider = $this->createMock(LoggerProviderInterface::class);
        $provider->expects(self::once())->method('getLogger')->with('app');
        $loggers = new ChannelLoggers($provider);

        self::assertSame($loggers->of('app'), $loggers->of('app'));
    }
}
