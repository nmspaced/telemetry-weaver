<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\Test;

/**
 * The middleware is registered by a pass rather than by services.php, so what the
 * pass decides is the thing worth compiling: the tag that makes DoctrineBundle pick
 * it up, and the two flags that decide whether it exists at all.
 */
final class DoctrineRegistrationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theMiddlewareIsTaggedSoDoctrineBundlePicksItUp(): void
    {
        $container = $this->compile();

        self::assertTrue($container->has(DoctrineMiddleware::class));
        self::assertArrayHasKey('doctrine.middleware', $container->getDefinition(DoctrineMiddleware::class)->getTags());
        self::assertInstanceOf(DoctrineMiddleware::class, $container->get(DoctrineMiddleware::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function bothSignalsOffMeansNoMiddlewareAtAll(): void
    {
        $container = $this->compile([
            'instrumentation' => ['doctrine' => ['traces' => false, 'metrics' => false]],
        ]);

        self::assertFalse($container->has(DoctrineMiddleware::class));
        self::assertFalse($container->has(DoctrinePolicy::class));
    }

    /**
     * A disabled signal is a no-op object, not a flag: the other signal keeps its real
     * one, and no instrumentation class is asked which half is on.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aDisabledSignalOnlyDisablesItsOwnHalf(): void
    {
        $container = $this->compile(['instrumentation' => ['doctrine' => ['traces' => false]]]);

        self::assertInstanceOf(NoOpSpanOpener::class, $container->get('open_telemetry.doctrine.span_opener'));
        self::assertNotInstanceOf(NoopMeter::class, $container->get('open_telemetry.doctrine.meter'));
    }

    /** @throws \Throwable */
    #[Test]
    public function turningOffMetricsLeavesANoopMeterAndARealSpanOpener(): void
    {
        $container = $this->compile(['instrumentation' => ['doctrine' => ['metrics' => false]]]);

        self::assertInstanceOf(NoopMeter::class, $container->get('open_telemetry.doctrine.meter'));
        self::assertInstanceOf(SpanOpener::class, $container->get('open_telemetry.doctrine.span_opener'));
    }

    /** @throws \Throwable */
    #[Test]
    public function turningOffTracingWholesaleTakesTheDoctrineSpansWithIt(): void
    {
        $container = $this->compile(['traces' => ['enabled' => false]]);

        self::assertInstanceOf(NoOpSpanOpener::class, $container->get('open_telemetry.doctrine.span_opener'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theConfiguredStatementAndParentFlagsReachThePolicy(): void
    {
        $container = $this->compile([
            'instrumentation' => ['doctrine' => ['query_text' => true, 'only_with_parent' => false]],
        ]);

        $policy = $container->get(DoctrinePolicy::class);
        self::assertInstanceOf(DoctrinePolicy::class, $policy);
        self::assertTrue($policy->recordStatements);
        self::assertTrue($policy->opensSpan(), 'only_with_parent: false must open a span without a parent');
    }

    /** @throws \Throwable */
    #[Test]
    public function transactionsAreRecordedByDefaultAndCanBeSwitchedOff(): void
    {
        $default = $this->compile()->get(DoctrinePolicy::class);
        self::assertInstanceOf(DoctrinePolicy::class, $default);
        self::assertTrue($default->recordTransactions);

        $off = $this->compile([
            'instrumentation' => ['doctrine' => ['transactions' => false]],
        ])->get(DoctrinePolicy::class);
        self::assertInstanceOf(DoctrinePolicy::class, $off);
        self::assertFalse($off->recordTransactions);
    }
}
