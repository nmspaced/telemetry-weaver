<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Reads the connection attributes once, at connect time. Extends DBAL's middleware base so
 * that methods added to `Driver` later are forwarded rather than fatal.
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
