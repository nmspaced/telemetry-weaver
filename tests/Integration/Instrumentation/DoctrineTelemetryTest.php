<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Doctrine\DBAL\Exception\DriverException;
use Nmspaced\TelemetryWeaver\Tests\Support\DoctrineTelemetryTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * Statement/query span shape: naming, attributes, statement text, prepare-vs-execute and error
 * recording. Signal on/off switches live in {@see DoctrineTelemetryPolicyTest}; transaction
 * boundaries live in {@see DoctrineTelemetryTransactionTest}.
 */
final class DoctrineTelemetryTest extends DoctrineTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aQueryIsNamedByItsSummaryAndCarriesTheConnectionAttributes(): void
    {
        $connection = $this->connection();
        $connection->executeQuery('SELECT id FROM users WHERE id = ?', [1]);

        $span = $this->exportedSpan();
        self::assertSame('SELECT users', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame(
            [
                'db.system.name' => 'postgresql',
                'db.namespace' => 'app',
                'server.address' => 'db.internal',
                'server.port' => 5432,
                'db.query.summary' => 'SELECT users',
                'db.query.text' => 'SELECT id FROM users WHERE id = ?',
            ],
            $span->getAttributes()->toArray(),
        );
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function preparingAStatementIsNotASpanButExecutingItIs(): void
    {
        $connection = $this->connection();

        $statement = $connection->prepare('SELECT id FROM users WHERE id = ?');
        self::assertSame([], $this->exportedNames(), 'prepare() must not open a span');

        $statement->bindValue(1, 1);
        $statement->executeQuery();

        self::assertSame(['SELECT users'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailedStatementCarriesTheErrorTypeAndTheSqlState(): void
    {
        $connection = $this->connection();

        try {
            $connection->executeQuery('SELECT * FROM missing_table');
            self::fail('the driver was expected to reject the statement');
        } catch (DriverException $driverException) {
            self::assertStringContainsString('missing_table', $driverException->getMessage());
        }

        $span = $this->exportedSpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $event = $span->getEvents()[0] ?? Assert::fail('span has no events');
        self::assertSame('exception', $event->getName());

        $attributes = $span->getAttributes()->toArray();
        self::assertArrayHasKey('error.type', $attributes);
        self::assertArrayHasKey('db.response.status_code', $attributes);
        self::assertSame('SELECT missing_table', $attributes['db.query.summary'] ?? null);
    }

    /** @throws \Throwable */
    #[Test]
    public function theDurationHistogramIsLabelledByTheConnectionOnly(): void
    {
        $connection = $this->connection();
        $connection->executeQuery('SELECT id FROM users');

        $this->reader->collect();

        self::assertSame(['db.client.operation.duration'], $this->recordedMetricNames());

        $duration = $this->histogram('db.client.operation.duration');
        self::assertSame(1, $duration->count);
        self::assertSame(
            [
                'db.system.name' => 'postgresql',
                'db.namespace' => 'app',
                'server.address' => 'db.internal',
                'server.port' => 5432,
            ],
            $duration->attributes->toArray(),
            'the summary is a span attribute; a table name in a label is unbounded the moment one is sharded',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function operationAndCollectionAreNeverReadFromTheStatement(): void
    {
        $connection = $this->connection();
        $connection->executeQuery('DELETE FROM users WHERE id = ?', [1]);

        $this->reader->collect();

        $span = $this->exportedSpan()->getAttributes()->toArray();
        $metric = $this->histogram('db.client.operation.duration')->attributes->toArray();

        foreach (['db.operation.name', 'db.collection.name'] as $attribute) {
            self::assertArrayNotHasKey($attribute, $span);
            self::assertArrayNotHasKey($attribute, $metric);
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function anUndescribableStatementIsNamedAfterTheSystem(): void
    {
        $connection = $this->connection();
        $connection->executeQuery('EXPLAIN SELECT id FROM users');

        $span = $this->exportedSpan();
        self::assertSame('postgresql', $span->getName());
        self::assertNull($span->getAttributes()->get('db.query.summary'));
    }
}
