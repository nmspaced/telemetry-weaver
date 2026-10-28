<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\QueryCaller;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderRepository;
use Nmspaced\TelemetryWeaver\Tests\Support\DoctrineTelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** `doctrine.record_caller`: which frame a query is attributed to, and where it is recorded. */
#[CoversClass(QueryCaller::class)]
#[CoversClass(DoctrineTelemetry::class)]
final class DoctrineQueryCallerTest extends DoctrineTelemetryTestCase
{
    private const string REPOSITORY_FILE = __DIR__ . '/../../Fake/OrderRepository.php';

    /** @throws \Throwable */
    #[Test]
    public function theQuerySpanNamesTheApplicationCodeThatIssuedIt(): void
    {
        $line = new OrderRepository($this->connection(caller: new QueryCaller()))->findOrder();

        $attributes = $this->exportedSpan()->getAttributes();
        self::assertSame(OrderRepository::class . '::findOrder', $attributes->get('code.function.name'));
        self::assertSame(\realpath(self::REPOSITORY_FILE), $attributes->get('code.file.path'));
        self::assertSame($line, $attributes->get('code.line.number'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anExcludedPathIsSkippedForTheCodeThatCalledIt(): void
    {
        $caller = new QueryCaller([\dirname((string) \realpath(self::REPOSITORY_FILE))]);

        new OrderRepository($this->connection(caller: $caller))->findOrder();

        $attributes = $this->exportedSpan()->getAttributes();
        self::assertSame(__CLASS__ . '::' . __FUNCTION__, $attributes->get('code.function.name'));
        self::assertSame(__FILE__, $attributes->get('code.file.path'));
    }

    /** @throws \Throwable */
    #[Test]
    public function aTransactionBoundaryNamesTheCodeThatEndedIt(): void
    {
        $connection = $this->connection(caller: new QueryCaller());

        $connection->beginTransaction();
        $connection->commit();

        self::assertSame(['BEGIN', 'COMMIT'], $this->exportedNames());
        self::assertSame(
            __CLASS__ . '::' . __FUNCTION__,
            $this->exportedSpan(1)->getAttributes()->get('code.function.name'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function theCallerNeverBecomesAMetricLabel(): void
    {
        new OrderRepository($this->connection(caller: new QueryCaller()))->findOrder();
        $this->reader->collect();

        $points = $this->histogramPoints('db.client.operation.duration');
        self::assertNotSame([], $points);
        foreach ($points as $point) {
            self::assertNull($point->attributes->get('code.function.name'));
            self::assertNull($point->attributes->get('code.file.path'));
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function offByDefault(): void
    {
        new OrderRepository($this->connection())->findOrder();

        self::assertNull($this->exportedSpan()->getAttributes()->get('code.function.name'));
    }

    /** @throws \Throwable */
    #[Test]
    public function withoutASpanNothingIsRecorded(): void
    {
        new OrderRepository($this->connection(
            new DoctrinePolicy(onlyWithParent: true),
            caller: new QueryCaller(),
        ))->findOrder();
        $this->reader->collect();

        self::assertSame([], $this->exportedNames());
        self::assertNotSame([], $this->histogramPoints('db.client.operation.duration'));
    }
}
