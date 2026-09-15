<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A CLIENT span and a duration per outgoing request, through Symfony's own async
 * decorator machinery.
 *
 * `AsyncDecoratorTrait` rather than a response wrapper of our own, because a Symfony
 * response is lazy and everything that makes it work — `stream()`, `cancel()`,
 * `getContent(false)`, the timeout chunks — is the state machine `AsyncResponse` already
 * implements. Wrapping the response instead would mean reimplementing that machine, and
 * the instrumentation would be the part that gets it subtly wrong.
 *
 * The operation ends when the response headers arrive, which is the boundary the HTTP
 * conventions define for a lazy client. Reading the body afterwards still reaches the
 * application if it fails, but it no longer changes a span that has already been closed
 * and possibly exported. A timeout chunk is not an ending either: the caller is allowed
 * to handle it and go on reading, and the request it is still waiting for is the same
 * request.
 *
 * Nothing about a request is held strongly. Pending operations are the keys of a
 * `\WeakMap`, and the only strong reference to an operation is the observing closure the
 * response itself holds — so a response that is dropped before it completes takes its
 * operation with it instead of leaving an entry in a process-lifetime service. What is
 * still pending when a worker resets is abandoned: the span is ended, because an unended
 * span keeps its context scope activated, and no duration is recorded, because nobody
 * saw the request finish.
 *
 * The map belongs to the individual client. `withOptions()` clones the decorator along
 * with its delegate and starts an empty one, so resetting one client cannot end another
 * client's requests and a scoped client's options never leak into the shared one.
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
            // Even a request excluded from both signals propagates the ambient parent:
            // exclusion says what this process records, not what the next one may know.
            $options = $this->instrumentation->inject($options);
            $response = new AsyncResponse(
                $this->client,
                $method,
                $url,
                $options,
                $call === null ? null : $this->observe($call),
            );

            if ($call !== null) {
                $this->instrumentation->describe($call->operation, $response);
            }

            return $response;
        } catch (\Throwable $throwable) {
            if ($call !== null) {
                $this->release($call);
                $call->operation->finish($throwable);
            }

            throw $throwable;
        } finally {
            // The span was only active long enough to parent itself and to be injected;
            // concurrent requests share the parent rather than nesting inside each other.
            $call?->operation->detach();
        }
    }

    /** @param array<array-key, mixed> $options */
    #[\Override]
    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->pending = new PendingOperations();
        $clone->client = $this->client->withOptions($options);

        if (\array_key_exists('base_uri', $options)) {
            $clone->baseUri = \is_string($options['base_uri']) ? $options['base_uri'] : null;
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
     * A URL whose host cannot be determined goes uninstrumented rather than producing a
     * span without the attributes the conventions require — but it is still propagated.
     *
     * @param array<array-key, mixed> $options
     */
    private function begin(string $method, string $url, array $options): ?ClientCall
    {
        /** @var mixed $baseUri */
        $baseUri = $options['base_uri'] ?? $this->baseUri;
        $call = $this->instrumentation->start($method, $url, \is_string($baseUri) ? $baseUri : null);

        if ($call !== null) {
            $this->pending->add($call->operation);
        }

        return $call;
    }

    /**
     * @return \Closure(ChunkInterface, AsyncContext): \Generator
     */
    private function observe(ClientCall $call): \Closure
    {
        return /** @throws TransportExceptionInterface */ function (ChunkInterface $chunk, AsyncContext $context) use (
            $call,
        ): \Generator {
            try {
                $this->settle($call, $chunk, $context);
            } catch (TransportExceptionInterface $error) {
                $this->release($call);
                $call->operation->finish($error);

                throw $error;
            } catch (\Throwable $error) {
                $this->release($call);
                $call->operation->abandon();
                $this->instrumentation->report('HTTP client response observation failed', $error);
            }

            yield $chunk;
        };
    }

    /**
     * @throws TransportExceptionInterface the request failed before any status was read
     */
    private function settle(ClientCall $call, ChunkInterface $chunk, AsyncContext $context): void
    {
        if ($context->getInfo('canceled') === true) {
            $this->release($call);
            $call->operation->abandon();
            $context->passthru();

            return;
        }

        // isTimeout() first: on a failed request isFirst() is what raises the transport
        // exception, and a timeout chunk must not be turned into one.
        if ($chunk->isTimeout() || !$chunk->isFirst()) {
            return;
        }

        $this->release($call);
        $this->instrumentation->complete($call, $context);
        $context->passthru();
    }

    private function release(ClientCall $call): void
    {
        $this->pending->release($call->operation);
    }
}
