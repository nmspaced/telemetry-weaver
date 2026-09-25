<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Injects the current context into outgoing carriers and extracts incoming ones.
 *
 * Extraction starts from the root, never the current context, so a message without trace
 * headers starts a new trace instead of joining a leftover one. Both directions fail open:
 * a broken propagator costs a trace, never the request or the message.
 *
 * @internal
 */
final readonly class OtelPropagation implements Propagation
{
    public function __construct(
        private TextMapPropagatorInterface $propagator,
        private ContextStorageInterface $contextStorage,
        private InstrumentationFailureReporter $reporter,
    ) {}

    #[\Override]
    public function injectCurrent(): array
    {
        /** @var mixed $carrier */
        $carrier = [];

        try {
            // @mago-expect analysis:mixed-assignment — inject() takes the carrier as `mixed &`
            $this->propagator->inject($carrier, null, $this->contextStorage->current());
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context injection failed', 'propagation', $throwable);

            return [];
        }

        return \is_array($carrier) ? self::strings($carrier) : [];
    }

    #[\Override]
    public function extract(array $carrier): IncomingTrace
    {
        try {
            return OtelIncomingTrace::extracted($this->propagator->extract($carrier, null, Context::getRoot()));
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context extraction failed', 'propagation', $throwable);

            return OtelIncomingTrace::none();
        }
    }

    #[\Override]
    public function fields(): array
    {
        $fields = [];

        try {
            $names = $this->propagator->fields();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context field names unavailable', 'propagation', $throwable);

            return [];
        }

        foreach ($names as $field) {
            if ($field === '') {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $carrier
     *
     * @return array<non-empty-string, string>
     */
    private static function strings(array $carrier): array
    {
        $headers = [];

        /** @var mixed $value */
        foreach ($carrier as $name => $value) {
            if (!\is_string($name) || $name === '' || !\is_string($value)) {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }
}
