<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Console;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionEntry;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;

/**
 * One command run, classified once at the end.
 *
 * The exception seen at `ConsoleEvents::ERROR` is held rather than recorded, because a
 * listener further down may handle it and set the exit code to zero. Symfony treats that
 * as a successful run and so does this: the outcome is whatever the exit code says at
 * `ConsoleEvents::TERMINATE`, and a recovered error leaves neither an errored span nor an
 * `error.type` in the histogram.
 *
 * The exit code is `process.exit.code` on the span, and the error type when it is not
 * zero — set through `fail()`, so the span and the histogram cannot disagree. It is a
 * small integer set, so it stays usable as a metric label, and it is what an operator
 * greps for — the exception class is on the span, where cardinality costs nothing.
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
