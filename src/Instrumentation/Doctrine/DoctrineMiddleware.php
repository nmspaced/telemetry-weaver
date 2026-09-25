<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL entry point; DoctrineBundle applies it to every connection via `doctrine.middleware`.
 */
final readonly class DoctrineMiddleware implements Middleware
{
    public function __construct(
        private DoctrineTelemetry $doctrineTelemetry,
    ) {}

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new TraceableDriver($driver, $this->doctrineTelemetry);
    }
}
