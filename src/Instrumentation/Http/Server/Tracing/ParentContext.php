<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Symfony\Component\HttpFoundation\Request;

/**
 * The trace a main request continues, read out of its headers.
 *
 * All this class does is turn a `HeaderBag` into a carrier. Resolving that carrier into a
 * trace — and deciding that an absent or unusable one means a *new* trace rather than the
 * leftovers of the previous request — belongs to {@see Propagation}, the only place that
 * should hold an opinion about OpenTelemetry's context model.
 *
 * There is no counterpart for sub-requests. A sub-request is not a process boundary: it
 * continues whatever the main request is doing, which an operation says by naming no
 * incoming trace at all. Re-reading the headers there would make it a second child of the
 * *caller's* span, beside the server span instead of inside it.
 */
final readonly class ParentContext
{
    public function __construct(
        private Propagation $propagation,
    ) {}

    /**
     * Main request: the headers are a real process boundary, so they decide.
     */
    public function fromHeaders(Request $request): IncomingTrace
    {
        return $this->propagation->extract(self::headers($request));
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function headers(Request $request): array
    {
        $carrier = [];

        // `get()` rather than walking `all()`: it already collapses the multi-value shape to
        // the first value, which is the only one a propagation field may have.
        foreach ($request->headers->keys() as $name) {
            $value = $request->headers->get($name);

            if ($name === '' || $value === null) {
                continue;
            }

            $carrier[$name] = $value;
        }

        return $carrier;
    }
}
