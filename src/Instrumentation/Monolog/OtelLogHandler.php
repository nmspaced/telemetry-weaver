<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\DiagnosticsLogger;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\LogCorrelation;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\Severity;

/**
 * Ships Monolog records through the OpenTelemetry Logs API.
 *
 * Deliberately ours rather than `open-telemetry/opentelemetry-logger-monolog`, whose
 * record shape this follows closely — a logger per channel, so each channel becomes its
 * own instrumentation scope; `Severity::fromPsr3`; `context.exception` promoted to the
 * record's exception; everything else flattened to `context.*` and `extra.*` attributes.
 * Three things in that package are not compatible with this one's guarantees, and none
 * of them can be fixed from outside it, because `write()` is protected and a decorator
 * cannot reach it:
 *
 *  - it catches nothing. `emit()` reaches the processor and, with a simple processor,
 *    the exporter; a collector that is down would then throw out of `$logger->error()`
 *    in application code. Telemetry is not allowed to do that here.
 *  - it has no re-entrance guard. This bundle reports export failures through a PSR
 *    logger, which in a Symfony application is Monolog — so a failing export logs, which
 *    emits, which exports, which fails. That is an unbounded loop, not a slow path.
 *  - its attribute mode is process-global mutable state (`private static $mode`) chosen
 *    by an environment variable. Both halves are wrong here: state that outlives a
 *    request has to justify itself, and `OTEL_*` owns the SDK and the export while what
 *    gets recorded belongs to the bundle's own configuration.
 *
 * Trace correlation is taken from the record, not from the context that happens to be
 * current when it is exported. The SDK resolves an unset context lazily — `Context::
 * getCurrent()` at the moment the record is read — which is the same thing as the active
 * span only while emit happens inside the operation that logged. Behind a `BufferHandler`
 * or `FingersCrossedHandler` it does not: the records are delivered at flush, by which
 * time the operation has finished and the record would be stamped with no trace at all,
 * or with whichever unrelated trace the flush ran inside.
 *
 * So the snapshot {@see TraceContextProcessor} takes when the record is created decides,
 * read back through {@see TraceContextSnapshot}. It can only be taken there: a handler
 * receives the record at flush, and a processor is the only part of Monolog that runs
 * while the record is still inside its operation. The handler is given a `LogCorrelation`
 * only when that processor is in the stack. Without it, the record carries no snapshot and
 * the SDK's own resolution is left in place. That is correct for an unbuffered stack, and
 * it is what `logs.correlation: false` has always meant.
 */
final class OtelLogHandler extends AbstractProcessingHandler
{
    private const string WHERE = 'monolog';

    /** One logger per channel, bounded; see `ChannelLoggers`. */
    private readonly ChannelLoggers $loggers;

    /**
     * True while a record is being exported.
     *
     * The export path logs when it fails, and that log would arrive back here. The guard
     * turns the second entry into a no-op, which loses the diagnostic about the failed
     * export — the right trade, because the alternative is a loop that never returns.
     */
    private bool $emitting = false;

    /** @var list<string> */
    private readonly array $refused;

    public function __construct(
        LoggerProviderInterface $loggerProvider,
        private readonly InstrumentationFailureReporter $reporter,
        LogExportPolicy $policy = new LogExportPolicy(),
        private readonly ?LogCorrelation $correlation = null,
        bool $bubble = true,
    ) {
        parent::__construct(self::level($policy->level), $bubble);
        $this->loggers = new ChannelLoggers($loggerProvider);
        // Added rather than left to configuration: the bundle's own diagnostics channel
        // carries the reports about failing exports, and exporting those is what turns one
        // unreachable collector into a queue that refills itself on every flush. A default
        // an application could overwrite would make that a foot-gun.
        $this->refused = [...$policy->excludedChannels, DiagnosticsLogger::CHANNEL];
    }

    /**
     * Excluded channels are dropped here rather than in write(), so they never reach
     * Monolog's formatting either.
     */
    #[\Override]
    public function isHandling(LogRecord $record): bool
    {
        return parent::isHandling($record) && !\in_array($record->channel, $this->refused, true);
    }

    #[\Override]
    protected function write(LogRecord $record): void
    {
        if ($this->emitting) {
            return;
        }

        $this->emitting = true;

        try {
            $this->emit($record);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Log export failed', self::WHERE, $throwable);
        } finally {
            $this->emitting = false;
        }
    }

    /**
     * @throws \Throwable whatever the SDK threw; write() is what contains it
     */
    private function emit(LogRecord $record): void
    {
        $builder = $this->loggers
            ->of($record->channel)
            ->logRecordBuilder()
            // Monolog keeps microseconds; the API wants nanoseconds.
            ->setTimestamp((int) $record->datetime->format('Uu') * 1_000)
            ->setSeverityNumber(Severity::fromPsr3($record->level->toPsrLogLevel()))
            ->setSeverityText($record->level->getName())
            ->setBody($record->message);

        $this->correlation?->correlate($builder, TraceContextSnapshot::read($record));

        /** @var mixed $value */
        foreach ($record->context as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                $builder->setException($value);

                continue;
            }

            $builder->setAttribute(\sprintf('context.%s', $key), self::scalar($value));
        }

        /** @var mixed $value */
        foreach ($record->extra as $key => $value) {
            if (\in_array($key, TraceContextSnapshot::KEYS, true)) {
                continue;
            }

            $builder->setAttribute(\sprintf('extra.%s', $key), self::scalar($value));
        }

        $builder->emit();
    }

    /**
     * The eight PSR-3 names, spelled out. The configuration tree already rejects
     * anything else, so the default is unreachable in practice — it is here because a
     * total match says what happens at the one place someone could reach it from,
     * which is constructing this handler by hand.
     */
    private static function level(string $name): Level
    {
        return match ($name) {
            'debug' => Level::Debug,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    /**
     * Attribute values are scalars, lists of scalars, or nothing. Anything else — an
     * object, a nested array, a resource — is rendered rather than dropped, because the
     * reason it was logged is usually visible in its rendering.
     */
    // @mago-expect lint:halstead — one total narrowing from mixed to what an attribute may hold; splitting it would spread a single decision across several places
    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return self::rendered($value);
    }

    private static function rendered(mixed $value): ?string
    {
        $json = \json_encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }
}
