<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

/**
 * The part of a database span's attributes that is the same for every statement on
 * one connection, computed once when the connection is opened.
 *
 * Read from the DBAL parameter array, never from the live connection: `getDatabase()`
 * and `getNativeConnection()` execute a query on several drivers, and telemetry must
 * not add round trips to the database it is measuring.
 *
 * This object is process-scoped by construction — a DBAL connection outlives the
 * request in worker mode, so one instance per connection survives for the life of the
 * process. That is bounded (one per configured connection, built once) and immutable,
 * which is why it is allowed to outlive an execution while per-request state is not.
 */
final readonly class ConnectionAttributes
{
    /**
     * @param array<non-empty-string, string|int> $attributes
     * @param non-empty-string $system
     */
    private function __construct(
        private array $attributes,
        public string $system,
    ) {}

    /**
     * @param array<string, mixed> $params DBAL connection parameters
     */
    public static function fromParams(array $params): self
    {
        $system = DatabaseSystem::of($params['driver'] ?? null);

        $attributes = [DbAttributes::DB_SYSTEM_NAME => $system];

        /** @var mixed $database */
        $database = $params['dbname'] ?? null;

        if (\is_string($database) && $database !== '') {
            $attributes[DbAttributes::DB_NAMESPACE] = $database;
        }

        /** @var mixed $host */
        $host = $params['host'] ?? null;

        if (\is_string($host) && $host !== '') {
            $attributes[ServerAttributes::SERVER_ADDRESS] = $host;
        }

        /** @var mixed $port */
        $port = $params['port'] ?? null;

        if (\is_int($port)) {
            $attributes[ServerAttributes::SERVER_PORT] = $port;
        }

        return new self($attributes, $system);
    }

    /**
     * The attributes every statement on this connection shares, and the complete label
     * set of `db.client.operation.duration` apart from the error ones.
     *
     * Nothing statement-specific is added here. `db.operation.name` and
     * `db.collection.name` are not read out of SQL text — the conventions advise against
     * it — and `db.query.summary` stays on the span, where a sharded table name costs
     * nothing, instead of on a histogram, where it makes the series count unbounded.
     *
     * @return array<non-empty-string, string|int>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
