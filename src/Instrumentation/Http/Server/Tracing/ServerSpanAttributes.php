<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\QueryStringRedactor;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Server span attributes known at `kernel.request`. Query values are always redacted;
 * `client.address` is recorded only with `record_client_ip`.
 */
final readonly class ServerSpanAttributes
{
    public function __construct(
        private bool $recordClientIp = false,
    ) {}

    /**
     * @return array<non-empty-string, string|int|float|bool|null>
     */
    public function from(Request $request, ?HttpMethod $method = null): array
    {
        $method ??= HttpMethod::from($request);
        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $method->value,
            UrlAttributes::URL_PATH => $request->getBaseUrl() . $request->getPathInfo(),
            UrlAttributes::URL_SCHEME => $request->getScheme(),
        ];

        if ($method->hasDistinctOriginal()) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL] = $method->original;
        }

        /** @var mixed $query */
        $query = $request->server->get('QUERY_STRING');

        if (\is_string($query) && $query !== '') {
            $attributes[UrlAttributes::URL_QUERY] = QueryStringRedactor::redact($query);
        }

        $host = $this->host($request);

        if ($host !== null) {
            $attributes[ServerAttributes::SERVER_ADDRESS] = $host;
            $attributes[ServerAttributes::SERVER_PORT] = $request->getPort();
        }

        $clientIp = $this->recordClientIp ? $request->getClientIp() : null;

        if ($clientIp !== null) {
            $attributes[ClientAttributes::CLIENT_ADDRESS] = $clientIp;
        }

        $userAgent = $request->headers->get('User-Agent');

        if ($userAgent !== null) {
            $attributes[UserAgentAttributes::USER_AGENT_ORIGINAL] = $userAgent;
        }

        $protocol = $request->getProtocolVersion();

        if ($protocol !== null && \str_starts_with($protocol, 'HTTP/')) {
            $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION] = \substr($protocol, 5);
        }

        return $attributes;
    }

    /**
     * Null for a suspicious Host, which must not fail the request.
     */
    private function host(Request $request): ?string
    {
        try {
            return $request->getHost();
        } catch (SuspiciousOperationException) {
            return null;
        }
    }
}
