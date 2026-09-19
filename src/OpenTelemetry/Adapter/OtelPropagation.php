<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

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
 * @internal
 */
final readonly class OtelPropagation implements Propagation
{
    public function __construct(
        private TextMapPropagatorInterface $propagator,
        private ContextStorageInterface $contextStorage,
    ) {}

    #[\Override]
    public function injectCurrent(): array
    {
        /** @var mixed $carrier */
        $carrier = [];

        // @mago-expect analysis:mixed-assignment — inject() takes the carrier as `mixed &`
        $this->propagator->inject($carrier, null, $this->contextStorage->current());

        return \is_array($carrier) ? self::strings($carrier) : [];
    }

    #[\Override]
    public function extract(array $carrier): IncomingTrace
    {
        return OtelIncomingTrace::extracted($this->propagator->extract($carrier, null, Context::getRoot()));
    }

    #[\Override]
    public function fields(): array
    {
        $fields = [];

        foreach ($this->propagator->fields() as $field) {
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
