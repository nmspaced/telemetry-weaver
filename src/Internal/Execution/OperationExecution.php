<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Execution;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;

/** @internal One registry entry owns one operation; errors are released on either terminal path. */
final class OperationExecution implements ExecutionEntry
{
    private ?\Throwable $error = null;

    private function __construct(
        private readonly RunningOperation $operation,
    ) {}

    public static function started(RunningOperation $operation): self
    {
        return new self($operation);
    }

    public function fail(\Throwable $error): void
    {
        $this->error = $error;
    }

    #[\Override]
    public function complete(): void
    {
        $error = $this->error;
        $this->error = null;

        $this->operation->finish($error);
    }

    #[\Override]
    public function abandon(): void
    {
        $this->error = null;
        $this->operation->abandon();
    }
}
