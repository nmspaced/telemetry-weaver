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
 * The one place in the package that decides which context propagation starts from.
 *
 * Outgoing uses the injected storage's current context: the caller is inside its own
 * operation, and that operation's span is what a downstream service should continue from.
 *
 * Incoming uses the root, never the current context, and that is the correctness rule this
 * class exists for rather than a style preference. `extract()` defaults to the current
 * context, so in a worker a request or message arriving without usable trace headers would
 * silently adopt whatever is still on the context stack — a scope some earlier unit of work
 * failed to close, or a span belonging to the previous message. Resolving against the root
 * makes an absent trace a new trace, which is the only honest answer.
 *
 * Both directions are fail-open, because a propagator is the one piece of the pipeline
 * that runs *before* the application's work rather than around it. A custom or misbuilt
 * propagator that threw out of `extract()` left `kernel.request` with the exception and
 * turned a working request into a 500; one that threw out of `inject()` reached the
 * Messenger sender first, so the transport was never called at all and the message was
 * lost to a telemetry failure. Failing propagation costs a trace, never a request or a
 * message: injection answers with no headers, and extraction answers with the root — a
 * *new* trace, not the ambient context, which in a worker is the previous unit of work.
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

            // Not null: null is how a caller says "continue whatever is running", and the
            // one thing a boundary must never do is adopt the previous unit of work.
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
