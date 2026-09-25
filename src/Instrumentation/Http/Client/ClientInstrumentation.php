<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The process-scoped services a decorated client uses to record a request, shared by every
 * tagged client.
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
     * @return ClientCall|null null when the URL has no host or the host is excluded from
     *                         both signals
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
