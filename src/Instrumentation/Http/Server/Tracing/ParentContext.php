<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use Symfony\Component\HttpFoundation\Request;

/**
 * The trace a main request continues, read out of its headers.
 *
 * Turns a `HeaderBag` into a carrier for {@see Propagation}. If a replacement propagation
 * implementation fails, the request still starts a new root; it must not inherit the
 * leftovers of the previous request.
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
        private InstrumentationFailureReporter $reporter,
    ) {}

    /**
     * Main request: the headers are a real process boundary, so they decide.
     */
    public function fromHeaders(Request $request): IncomingTrace
    {
        try {
            return $this->propagation->extract(self::headers($request));
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP parent context extraction failed', 'http_server', $throwable);

            return new RootTrace();
        }
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
