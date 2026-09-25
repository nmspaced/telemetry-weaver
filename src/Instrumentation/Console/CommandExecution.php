<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Console;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionEntry;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;

/**
 * One command run, whose outcome is decided by the exit code at `ConsoleEvents::TERMINATE`.
 *
 * An error seen at `ConsoleEvents::ERROR` is held, since a listener may recover it with exit
 * code 0. A non-zero exit code becomes the error type on both the span and the histogram.
 */
final class CommandExecution implements ExecutionEntry
{
    private ?\Throwable $error = null;

    private int $exitCode = 0;

    private function __construct(
        private readonly RunningOperation $operation,
    ) {}

    public static function started(RunningOperation $operation): self
    {
        return new self($operation);
    }

    public function error(\Throwable $error): void
    {
        $this->error = $error;
    }

    public function exitCode(int $code): void
    {
        $this->exitCode = $code;
    }

    #[\Override]
    public function complete(): void
    {
        $error = $this->error;
        $this->error = null;

        $this->operation->span()->attribute(ProcessIncubatingAttributes::PROCESS_EXIT_CODE, $this->exitCode);

        if ($this->exitCode === 0) {
            $this->operation->finish();

            return;
        }

        $this->operation->fail((string) $this->exitCode);
        $this->operation->finish($error);
    }

    #[\Override]
    public function abandon(): void
    {
        $this->error = null;
        $this->operation->abandon();
    }
}
