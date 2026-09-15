<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver\Exception;

/**
 * A driver-level failure with a SQLSTATE, which is what the instrumentation reads for
 * db.response.status_code. "HY000" is the general-error state every driver uses.
 */
final class FakeDriverException extends \RuntimeException implements Exception
{
    public function __construct(
        string $message,
        private readonly string $sqlState = 'HY000',
    ) {
        parent::__construct($message, 1);
    }

    #[\Override]
    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
