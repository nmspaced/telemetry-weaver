<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtelPropagation::class)]
final class OtelPropagationTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function onlyNamedStringHeadersAreInjectedAndEmptyFieldNamesAreDropped(): void
    {
        $propagator = new readonly class implements TextMapPropagatorInterface {
            #[\Override]
            public function fields(): array
            {
                return ['traceparent', '', 'tracestate'];
            }

            /** @param mixed $carrier */
            #[\Override]
            public function inject(
                &$carrier,
                ?PropagationSetterInterface $setter = null,
                ?ContextInterface $context = null,
            ): void {
                $carrier = [
                    'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
                    '' => 'unnamed',
                    0 => 'positional',
                    'tracestate' => ['not', 'a', 'string'],
                ];
            }

            #[\Override]
            public function extract(
                $carrier,
                ?PropagationGetterInterface $getter = null,
                ?ContextInterface $context = null,
            ): ContextInterface {
                return $context ?? Context::getRoot();
            }
        };
        $propagation = new OtelPropagation(
            $propagator,
            new ContextStorage(),
            new InstrumentationFailureReporter(new RecordingLogger()),
        );

        self::assertSame(['traceparent', 'tracestate'], $propagation->fields());
        self::assertSame(
            ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
            $propagation->injectCurrent(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function unavailableFieldNamesAreReportedAsNone(): void
    {
        $propagator = $this->createStub(TextMapPropagatorInterface::class);
        $propagator->method('fields')->willThrowException(new \RuntimeException('propagator is broken'));
        $logger = new RecordingLogger();
        $propagation = new OtelPropagation(
            $propagator,
            new ContextStorage(),
            new InstrumentationFailureReporter($logger),
        );

        self::assertSame([], $propagation->fields());
        self::assertSame(
            [
                'OpenTelemetry lifecycle: Context field names unavailable at "propagation": propagator is broken (1 total in this process)',
            ],
            $logger->messages(),
        );
    }
}
