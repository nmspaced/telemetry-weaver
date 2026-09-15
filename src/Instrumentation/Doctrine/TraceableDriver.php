<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Reads the connection's attributes once, at connect time, and hands them to the
 * connection wrapper so no statement ever has to look at the parameters again.
 *
 * Extends DBAL's own middleware base class rather than implementing `Driver` directly:
 * the base forwards everything this class does not touch, so a method added to the
 * interface in a future DBAL release cannot turn into a fatal here. The consequence is
 * that this class cannot be `readonly` — the parent is not — which is also why it
 * holds nothing beyond the two constructor arguments.
 */
final class TraceableDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly DoctrineTelemetry $doctrineTelemetry,
    ) {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     */
    #[\Override]
    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new TraceableConnection(
            parent::connect($params),
            ConnectionAttributes::fromParams($params),
            $this->doctrineTelemetry,
        );
    }
}
