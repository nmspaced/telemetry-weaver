<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Everything a decorated client needs in order to record a request.
 *
 * One collaborator instead of four. The split is between two kinds of thing: what is
 * recorded about a request, which is process-scoped and identical for every client in
 * the application, and who owns an individual request, which belongs to the one client
 * that started it. This is the first kind, and holding it together is what keeps
 * `TraceableHttpClient` about the second — that class already has Symfony's async
 * response state machine to get right, and it should not also be a list of four
 * telemetry services it forwards to.
 *
 * It also makes the decoration cheap to wire: a pass registers this once and hands the
 * same reference to every tagged client.
 */
final readonly class ClientInstrumentation
{
    private const string WHERE = 'http_client';

    public function __construct(
        private HttpClientTelemetry $telemetry,
        private RequestPropagation $propagation,
        private ResponseMetadata $metadata,
        private InstrumentationFailureReporter $reporter,
    ) {}

    /**
     * @param string|null $baseUri what a relative URL resolves against, when the
     *                             decorated client has not resolved one already
     *
     * @return ClientCall|null null when there is nothing to record: the URL has no
     *                         determinable host, or the host is excluded from both
     *                         signals
     */
    public function start(string $method, string $url, ?string $baseUri): ?ClientCall
    {
        $request = OutgoingRequest::from($method, $url, $baseUri);
        if ($request === null) {
            return null;
        }

        $operation = $this->telemetry->start($request);

        return $operation === null ? null : new ClientCall($operation, $request);
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    public function inject(array $options): array
    {
        return $this->propagation->inject($options);
    }

    public function describe(RunningOperation $operation, ResponseInterface $response): void
    {
        $this->metadata->describe($operation, $response);
    }

    public function complete(ClientCall $call, AsyncContext $context): void
    {
        $this->telemetry->complete(
            $call,
            $context->getStatusCode(),
            ClientBodySize::of($context),
            ResponseProtocol::of($context->getInfo('response_headers')),
        );
    }

    public function report(string $what, \Throwable $error): void
    {
        $this->reporter->report($what, self::WHERE, $error);
    }
}
