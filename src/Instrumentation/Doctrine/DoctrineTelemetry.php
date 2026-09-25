<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

/**
 * @internal SQL semantics stay here; the public operation API owns tracing and measurement.
 */
final readonly class DoctrineTelemetry
{
    public const string TRANSACTION_BEGIN = 'BEGIN';

    public const string TRANSACTION_COMMIT = 'COMMIT';

    public const string TRANSACTION_ROLLBACK = 'ROLLBACK';

    private Duration $duration;

    public function __construct(
        private BoundaryTelemetry $telemetry,
        private DoctrinePolicy $policy = new DoctrinePolicy(),
        OperationBuckets $buckets = DefaultBuckets::Database,
    ) {
        $this->duration = $telemetry->metrics()->duration(
            DbMetrics::DB_CLIENT_OPERATION_DURATION,
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of database client operations.',
        );
    }

    /**
     * @template T
     * @param \Closure(Span): T $callback
     * @return T
     * @throws \Throwable whatever the driver throws, untouched
     */
    public function run(string $sql, ConnectionAttributes $connection, \Closure $callback): mixed
    {
        // Lexed once: the summary and the sanitized text are two readings of the same code.
        $code = SqlLexer::code($sql, $connection->system);
        $summary = SqlSummary::fromCode($code)->value;
        $attributes = $connection->attributes();
        $spanAttributes = $summary === null
            ? $attributes
            : [...$attributes, DbAttributes::DB_QUERY_SUMMARY => $summary];

        $text = match ($this->policy->queryText) {
            QueryText::Sanitized => SqlQueryText::sanitized($code),
            QueryText::Raw => $sql,
            QueryText::Off => null,
        };

        if ($text !== null && $text !== '') {
            $spanAttributes[DbAttributes::DB_QUERY_TEXT] = $text;
        }

        return $this->measure($summary ?? $connection->system, $spanAttributes, $attributes, $callback);
    }

    /**
     * A transaction boundary — BEGIN, COMMIT or ROLLBACK — as an operation of its own.
     *
     * One span per round trip, never one span for the whole open transaction: that would
     * measure the application's time between the two calls, and a slow COMMIT — the thing
     * worth an alert — would vanish inside it. The operation name comes from the DBAL
     * method that was called, which is exactly the case the conventions reserve
     * `db.operation.name` for, and it is a label as well: three values, from the API,
     * never from text.
     *
     * Nesting needs nothing here. DBAL calls the driver's transaction methods only for
     * the outermost level and turns inner levels into `SAVEPOINT` / `RELEASE` statements,
     * which are traced by `run()` like any other statement.
     *
     * @template T
     * @param non-empty-string $operation one of the TRANSACTION_* constants
     * @param \Closure(): T $callback
     * @return T
     * @throws \Throwable whatever the driver throws, untouched
     */
    public function transaction(string $operation, ConnectionAttributes $connection, \Closure $callback): mixed
    {
        if (!$this->policy->recordTransactions) {
            return $callback();
        }

        $attributes = [...$connection->attributes(), DbAttributes::DB_OPERATION_NAME => $operation];

        return $this->measure(
            $operation,
            $attributes,
            $attributes,
            /** @throws \Throwable */ static fn(Span $_): mixed => $callback(),
        );
    }

    /**
     * @template T
     * @param non-empty-string $name
     * @param array<non-empty-string, string|int> $spanAttributes
     * @param array<non-empty-string, string|int> $metricAttributes
     * @param \Closure(Span): T $callback
     * @return T
     * @throws \Throwable whatever the driver throws, untouched
     */
    private function measure(string $name, array $spanAttributes, array $metricAttributes, \Closure $callback): mixed
    {
        $operation = $this->telemetry
            ->boundary($name)
            ->kind(SpanKind::Client)
            ->attributes($spanAttributes)
            ->duration($this->duration, attributes: $metricAttributes);

        if ($this->policy->onlyWithParent) {
            $operation = $operation->onlyInsideTrace();
        }

        return $operation->run(
            /** @throws \Throwable */ function (OperationContext $context) use ($callback): mixed {
                try {
                    return $callback($context->span());
                } catch (\Throwable $throwable) {
                    $attributes = $this->errorAttributes($throwable);
                    $context->span()->attributes($attributes);
                    $context->metricAttributes($attributes);
                    throw $throwable;
                }
            },
        );
    }

    /**
     * SQLSTATE is what the conventions ask for in `db.response.status_code`, and DBAL
     * exposes it on every driver exception. A failure that is not a driver exception
     * (a bug in the callback, say) still gets `error.type`.
     *
     * @return array<non-empty-string, string>
     */
    private function errorAttributes(\Throwable $e): array
    {
        $attributes = [ErrorAttributes::ERROR_TYPE => $e::class];

        if ($e instanceof DriverException) {
            $state = $e->getSQLState();

            if ($state !== null && $state !== '') {
                $attributes[DbAttributes::DB_RESPONSE_STATUS_CODE] = $state;
            }
        }

        return $attributes;
    }
}
