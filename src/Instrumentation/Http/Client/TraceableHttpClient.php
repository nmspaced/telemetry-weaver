<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Internal\Operation\PendingOperations;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A CLIENT span and a duration per outgoing request, built on Symfony's `AsyncDecoratorTrait`.
 *
 * The operation ends when the response headers arrive. Pending operations are held weakly, so
 * a dropped response takes its operation with it; `reset()` abandons whatever is still pending.
 * Each client instance, including each `withOptions()` clone, has its own pending set.
 */
final class TraceableHttpClient implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait {
        reset as private resetClient;
    }

    private PendingOperations $pending;

    public function __construct(
        HttpClientInterface $client,
        private readonly ClientInstrumentation $instrumentation,
        private ?string $baseUri = null,
    ) {
        $this->client = $client;
        $this->pending = new PendingOperations();
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @throws \Throwable whatever the decorated client threw
     */
    #[\Override]
    public function request(string $method, string $url, array $options = []): AsyncResponse
    {
        $call = $this->begin($method, $url, $options);

        try {
            $options = $this->instrumentation->inject($options);
            $response = new AsyncResponse($this->client, $method, $url, $options, $this->observe($call));

            if ($call !== null) {
                $this->instrumentation->describe($call->operation, $response);
            }

            return $response;
        } catch (\Throwable $throwable) {
            if ($call !== null) {
                $this->pending->finish($call->operation, $throwable);
            }

            throw $throwable;
        } finally {
            $call?->operation->detach();
        }
    }

    /** @param array<array-key, mixed> $options */
    // @mago-expect lint:redundant-static — required by HttpClientInterface
    #[\Override]
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->pending = new PendingOperations();
        $clone->client = $this->client->withOptions($options);

        if (\array_key_exists('base_uri', $options)) {
            $clone->baseUri = self::uri($options['base_uri']);
        }

        return $clone;
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending->abandonAll();
        $this->resetClient();
    }

    /**
     * Null when the URL has no usable host; such a request is still propagated.
     *
     * @param array<array-key, mixed> $options
     */
    private function begin(string $method, string $url, array $options): ?ClientCall
    {
        /** @var mixed $baseUri */
        $baseUri = $options['base_uri'] ?? $this->baseUri;
        $call = $this->instrumentation->start($method, $url, self::uri($baseUri));

        if ($call !== null) {
            $this->pending->add($call->operation);
        }

        return $call;
    }

    /**
     * Null for an untraced request, which then needs no response observer.
     *
     * @return (\Closure(ChunkInterface, AsyncContext): \Generator)|null
     */
    private function observe(?ClientCall $call): ?\Closure
    {
        if ($call === null) {
            return null;
        }

        return /** @throws TransportExceptionInterface */ function (ChunkInterface $chunk, AsyncContext $context) use (
            $call,
        ): \Generator {
            try {
                $this->settle($call, $chunk, $context);
            } catch (TransportExceptionInterface $error) {
                $this->pending->finish($call->operation, $error);

                throw $error;
            } catch (\Throwable $error) {
                $this->pending->abandon($call->operation);
                $this->instrumentation->report('HTTP client response observation failed', $error);
            }

            yield $chunk;
        };
    }

    /**
     * Checks `isTimeout()` before `isFirst()`, which throws on a failed request.
     *
     * @throws TransportExceptionInterface the request failed before any status was read
     */
    private function settle(ClientCall $call, ChunkInterface $chunk, AsyncContext $context): void
    {
        if ($context->getInfo('canceled') === true) {
            $this->pending->abandon($call->operation);
            $context->passthru();

            return;
        }

        if ($chunk->isTimeout() || !$chunk->isFirst()) {
            return;
        }

        $this->pending->release($call->operation);
        $this->instrumentation->complete($call, $context);
        $context->passthru();
    }

    private static function uri(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return $value;
    }
}
