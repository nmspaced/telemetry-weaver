<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Tests\Fake\FakeDbalConnection;
use Nmspaced\TelemetryWeaver\Tests\Support\DoctrineTelemetryTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * Transaction boundaries: BEGIN/COMMIT/ROLLBACK spans, savepoint nesting, and the
 * `only_with_parent` / `record_transactions` policy switches applied to boundaries. Statement/query
 * span shape lives in {@see DoctrineTelemetryTest}; the other signal switches live in {@see
 * DoctrineTelemetryPolicyTest}.
 */
final class DoctrineTelemetryTransactionTest extends DoctrineTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aCommittedTransactionIsItsBoundariesAndItsStatements(): void
    {
        $connection = $this->connection();

        $connection->transactional(
            /** @throws Exception */
            static function (Connection $connection): void {
                $connection->executeStatement('UPDATE users SET name = ? WHERE id = ?', ['x', 1]);
            },
        );

        self::assertSame(['BEGIN', 'UPDATE users', 'COMMIT'], $this->exportedNames());

        $commit = $this->exportedSpan(2);
        self::assertSame(SpanKind::KIND_CLIENT, $commit->getKind());
        self::assertSame(
            [
                'db.system.name' => 'postgresql',
                'db.namespace' => 'app',
                'server.address' => 'db.internal',
                'server.port' => 5432,
                'db.operation.name' => 'COMMIT',
            ],
            $commit->getAttributes()->toArray(),
            'the operation name comes from the API method called, which the conventions allow',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aRolledBackTransactionEndsWithARollbackSpan(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->executeQuery('SELECT id FROM users');
        $connection->rollBack();

        self::assertSame(['BEGIN', 'SELECT users', 'ROLLBACK'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function nestedTransactionsAreSavepointsInsideOneBoundaryPair(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM sessions');
        $connection->commit();
        $connection->commit();

        $names = $this->exportedNames();
        self::assertSame('BEGIN', $names[0] ?? Assert::fail('no spans exported'));
        self::assertSame('COMMIT', $names[\count($names) - 1] ?? Assert::fail('no spans exported'));
        self::assertSame(1, \count(\array_keys($names, 'BEGIN', true)));
        self::assertSame(1, \count(\array_keys($names, 'COMMIT', true)));
        self::assertContains('SAVEPOINT', $names);
        self::assertContains('DELETE sessions', $names);
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailedCommitCarriesTheErrorOnBothSignals(): void
    {
        $connection = $this->connection();
        $native = $connection->getNativeConnection();
        self::assertInstanceOf(FakeDbalConnection::class, $native);
        $native->driver()->failCommit = true;

        $connection->beginTransaction();

        try {
            $connection->commit();
            self::fail('the driver was expected to reject the commit');
        } catch (DriverException $driverException) {
            self::assertSame('HY000', $driverException->getSQLState());
        }

        $this->reader->collect();

        $commit = $this->exportedSpan(1);
        self::assertSame('COMMIT', $commit->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $commit->getStatus()->getCode());
        self::assertSame('HY000', $commit->getAttributes()->get('db.response.status_code'));

        $failed = \array_values(\array_filter(
            $this->histogramPoints('db.client.operation.duration'),
            static fn(HistogramDataPoint $point): bool => $point->attributes->get('db.operation.name') === 'COMMIT',
        ));
        self::assertCount(1, $failed);
        $failedPoint = $failed[0] ?? Assert::fail('no failed commit data point');
        self::assertNotNull($failedPoint->attributes->get('error.type'));
    }

    /** @throws \Throwable */
    #[Test]
    public function transactionBoundariesAreMeasuredUnderTheirOperationName(): void
    {
        $connection = $this->connection();

        $connection->transactional(
            /** @throws Exception */
            static function (Connection $connection): void {
                $connection->executeQuery('SELECT id FROM users');
            },
        );
        $this->reader->collect();

        $operations = \array_map(static fn(HistogramDataPoint $point): mixed => $point->attributes->get(
            'db.operation.name',
        ), $this->histogramPoints('db.client.operation.duration'));

        self::assertEqualsCanonicalizing(
            [null, 'BEGIN', 'COMMIT'],
            $operations,
            'the statement has no operation label; each boundary has its own',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function transactionsCanBeLeftOutWhileStatementsStay(): void
    {
        $connection = $this->connection(new DoctrinePolicy(onlyWithParent: false, recordTransactions: false));

        $connection->transactional(
            /** @throws Exception */
            static function (Connection $connection): void {
                $connection->executeQuery('SELECT id FROM users');
            },
        );
        $this->reader->collect();

        self::assertSame(['SELECT users'], $this->exportedNames());
        self::assertCount(1, $this->histogramPoints('db.client.operation.duration'));
    }

    /** @throws \Throwable */
    #[Test]
    public function orphanTransactionsAreMeasuredButNotTraced(): void
    {
        $connection = $this->connection(new DoctrinePolicy(onlyWithParent: true));

        $connection->transactional(
            /** @throws Exception */
            static function (Connection $connection): void {
                $connection->executeQuery('SELECT id FROM users');
            },
        );
        $this->reader->collect();

        self::assertSame([], $this->exportedNames());
        self::assertCount(3, $this->histogramPoints('db.client.operation.duration'), 'the statement, BEGIN and COMMIT');
    }
}
