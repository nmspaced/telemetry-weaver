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
    /** The one propagation field a valid request carries at most once. */
    private const string SINGLE_VALUED = 'traceparent';

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
     * The request's headers as one string per field, which is the shape a propagator reads.
     *
     * A field may arrive as several header lines. `HeaderBag::get()` returns the first line
     * and drops the rest. That is wrong for the two propagation fields that are defined as
     * lists: W3C Baggage entries may arrive as separate lines, and `tracestate` has an explicit
     * rule for combining them in order. Dropping a line silently loses vendor entries and
     * tenant values that the caller expects downstream. The lines are therefore joined with
     * a comma, which is the combined form HTTP defines for list-valued fields.
     *
     * Whether the lines reach PHP separately depends on what is in front of it. Some
     * FastCGI servers fold them, some keep only one, and a PSR-7 bridge such as RoadRunner's
     * hands the `HeaderBag` every line. This produces the same carrier in all of those cases.
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

            // `traceparent` is single-valued by definition, so a second line is not a longer
            // field. It means two callers disagree about the parent, and joining them could
            // build a syntactically valid header out of an ambiguous request. Dropping it
            // starts a new root, which is what an unusable boundary means everywhere else
            // here. Any baggage that arrived alongside it is independent and still applies.
            // `HeaderBag` has already lower-cased the name.
            if ($present === [] || $name === self::SINGLE_VALUED && \count($present) > 1) {
                continue;
            }

            $carrier[$name] = \implode(',', $present);
        }

        return $carrier;
    }
}
