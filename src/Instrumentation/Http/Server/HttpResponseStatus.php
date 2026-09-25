<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server;

use Symfony\Component\HttpFoundation\Response;

final readonly class HttpResponseStatus
{
    private function __construct(
        public int $code,
    ) {}

    public static function fromResponse(Response $response): self
    {
        return new self($response->getStatusCode());
    }

    public function isServerError(): bool
    {
        return $this->code >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function errorType(): ?string
    {
        if (!$this->isServerError()) {
            return null;
        }

        return (string) $this->code;
    }
}
