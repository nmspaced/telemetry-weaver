<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Console;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionRegistry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One `console {command}` operation per command run, keyed by its input so nested runs of the
 * same command stay apart.
 *
 * Arguments and options are never recorded, since they may carry secrets. Process attributes
 * go on the span only, not the histogram.
 */
final readonly class ConsoleTelemetrySubscriber implements EventSubscriberInterface, ResetInterface
{
    private const string COMMAND_NAME = 'console.command.name';

    /** @var ExecutionRegistry<CommandExecution> */
    private ExecutionRegistry $executions;

    private Duration $duration;

    /** @param list<string> $excludedCommands names to leave uninstrumented */
    public function __construct(
        private Telemetry $telemetry,
        InstrumentationFailureReporter $reporter,
        private array $excludedCommands = [],
        OperationBuckets $buckets = DefaultBuckets::Command,
    ) {
        $this->executions = new ExecutionRegistry($reporter, 'console');
        $this->duration = $telemetry->metrics()->duration(
            'console.command.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of running a console command.',
        );
    }

    /** @return array<string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', -4096],
            ConsoleEvents::ERROR => ['onError', -4096],
            ConsoleEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();

        if ($name === null || $name === '' || !$event->commandShouldRun() || $this->isExcluded($name)) {
            return;
        }

        $attributes = [self::COMMAND_NAME => $name];
        $spanAttributes = [
            ...$attributes,
            ProcessIncubatingAttributes::PROCESS_EXECUTABLE_NAME => \basename(\PHP_BINARY),
            ProcessIncubatingAttributes::PROCESS_PID => \getmypid(),
        ];

        $this->executions->open($event->getInput(), fn(): CommandExecution => CommandExecution::started(
            $this->telemetry
                ->operation(\sprintf('console %s', $name))
                ->attributes($spanAttributes)
                ->duration($this->duration, attributes: $attributes)
                ->start(),
        ));
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $this->executions->of($event->getInput())?->error($event->getError());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $input = $event->getInput();
        $this->executions->of($input)?->exitCode($event->getExitCode());
        $this->executions->close($input);
    }

    #[\Override]
    public function reset(): void
    {
        $this->executions->reset();
    }

    private function isExcluded(string $name): bool
    {
        return \in_array($name, $this->excludedCommands, true);
    }
}
