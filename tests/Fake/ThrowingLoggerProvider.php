<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;

/** A logger provider that throws; `$before` runs first, so a test can make the failure log. */
final class ThrowingLoggerProvider implements LoggerProviderInterface
{
    public int $attempts = 0;

    public function __construct(
        private readonly ?\Closure $before = null,
    ) {}

    /**
     * @param iterable<mixed, mixed> $attributes
     *
     * @throws \RuntimeException always
     */
    #[\Override]
    public function getLogger(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): LoggerInterface {
        ++$this->attempts;
        ($this->before ?? static fn(): null => null)();

        throw new \RuntimeException('collector unreachable');
    }
}
