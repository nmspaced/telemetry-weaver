<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use Symfony\Component\HttpFoundation\Request;

/**
 * The trace a main request continues, read from its headers. A failed extraction starts a
 * new root; sub-requests do not read headers and continue the main request.
 */
final readonly class ParentContext
{
    /** Two of these make a request ambiguous, so it starts a new trace. */
    private const string SINGLE_VALUED = 'traceparent';

    public function __construct(
        private Propagation $propagation,
        private InstrumentationFailureReporter $reporter,
    ) {}

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
     * One string per header, with repeated lines joined by commas as HTTP defines, so
     * multi-line `baggage` and `tracestate` keep every entry.
     *
     * @return array<non-empty-string, string>
     */
    private static function headers(Request $request): array
    {
        $carrier = [];

        foreach ($request->headers->all() as $name => $lines) {
            if ($name === '') {
                continue;
            }

            $present = [];

            foreach ($lines as $line) {
                if ($line === null) {
                    continue;
                }

                $present[] = $line;
            }

            if ($present === [] || $name === self::SINGLE_VALUED && \count($present) > 1) {
                continue;
            }

            $carrier[$name] = \implode(',', $present);
        }

        return $carrier;
    }
}
