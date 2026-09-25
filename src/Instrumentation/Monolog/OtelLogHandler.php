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
 * Exports Monolog records through the OpenTelemetry Logs API, one logger per channel.
 *
 * Unlike `open-telemetry/opentelemetry-logger-monolog`, it never throws into application
 * code, guards against re-entry when an export failure is logged, and takes a record's trace
 * from its snapshot so buffered records keep the trace they were written in.
 */
final class OtelLogHandler extends AbstractProcessingHandler
{
    private const string WHERE = 'monolog';

    private readonly ChannelLoggers $loggers;

    /** Re-entry guard: an export failure is logged and would otherwise loop back here. */
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
        $this->refused = [...$policy->excludedChannels, DiagnosticsLogger::CHANNEL];
    }

    /** The diagnostics channel is always excluded, so export failures are never exported. */
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
     * @throws \Throwable whatever the SDK threw; write() contains it
     */
    private function emit(LogRecord $record): void
    {
        $builder = $this->loggers
            ->of($record->channel)
            ->logRecordBuilder()
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

    /** A PSR-3 level name; unknown names fall back to info. */
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

    /** An attribute value; anything that is not scalar is rendered as JSON rather than dropped. */
    // @mago-expect lint:halstead — one total narrowing from mixed to an attribute value
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

        if ($json === false) {
            return null;
        }

        return $json;
    }
}
