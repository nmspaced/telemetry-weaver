<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\KnownHttpMethods;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionRegistry;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The in-flight HTTP server measurements, keyed by their request.
 *
 * Only main requests are measured, so there is at most one entry at a time —
 * but it is keyed and released exactly like the trace registry, because the
 * question "is everything released at the end of an execution" has to have
 * one answer for both signals, not two implementations of one idea.
 */
final readonly class RequestMeasurementRegistry implements ResetInterface
{
    /**
     * @var ExecutionRegistry<RequestMeasurement>
     */
    private ExecutionRegistry $measurements;

    public function __construct(
        private HttpServerMetrics $httpServerMetrics,
        private InstrumentationFailureReporter $reporter,
    ) {
        $this->measurements = new ExecutionRegistry($reporter, 'http.server metrics');
    }

    public function of(Request $request): ?RequestMeasurement
    {
        return $this->measurements->of($request);
    }

    public function open(Request $request, KnownHttpMethods $knownMethods): void
    {
        try {
            $method = HttpMethod::from($request, $knownMethods);
            $metrics = $this->httpServerMetrics;
            $attributes = [
                HttpAttributes::HTTP_REQUEST_METHOD => $method->value,
                UrlAttributes::URL_SCHEME => $request->getScheme(),
            ];
            $bodySize = HttpBodySize::ofRequest($request);
            $protocol = $request->getProtocolVersion();

            $measurement = $this->measurements->open(
                $request,
                static fn(): RequestMeasurement => new RequestMeasurement($metrics, $attributes, $bodySize),
            );

            $measurement->protocol($protocol);
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP measurement setup failed', 'kernel.request', $throwable);
        }
    }

    public function route(Request $request, RequestPolicy $policy): void
    {
        $measurement = $this->of($request);

        if ($measurement === null) {
            return;
        }

        try {
            $route = $policy->routeTemplate($request);

            if ($route !== null) {
                $measurement->route($route);
            }
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP metric route resolution failed', 'http.route', $throwable);
        }
    }

    public function finish(Request $request): void
    {
        try {
            $this->measurements->close($request);
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP duration recording failed', 'http.server.request.duration', $throwable);
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->measurements->reset();
    }
}
