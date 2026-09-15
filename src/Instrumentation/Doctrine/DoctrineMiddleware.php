<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * The entry point DBAL offers for third-party instrumentation. DoctrineBundle hands
 * this to every configured connection through the `doctrine.middleware` tag.
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
