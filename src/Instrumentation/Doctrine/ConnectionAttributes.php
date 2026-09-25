<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

/**
 * Attributes shared by every statement on one connection, built once from the DBAL
 * parameters. The live connection is never asked, because some drivers answer with a query.
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
     * Also the full label set of `db.client.operation.duration`, apart from error labels.
     *
     * @return array<non-empty-string, string|int>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
