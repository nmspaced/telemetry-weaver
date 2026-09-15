<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Symfony\Component\HttpClient\Response\AsyncContext;

/**
 * The two body sizes, read at the moment the response headers arrive.
 *
 * Both come from information the client already has, so reading them neither sends
 * anything nor waits: the request body is whatever the transport reports having
 * uploaded, and the response body is the length the server declared. Nothing is
 * buffered and no response is consumed to produce a number — a streamed download must
 * stay streamed, and the point of ending the operation at the headers is lost if
 * measuring it means reading to the end.
 *
 * Either can be absent and absent is not zero. A chunked or compressed response
 * declares no length, and a transport that does not track the upload reports nothing;
 * recording a 0 in those cases would put a false value in the histogram rather than
 * leave a gap in it.
 */
final readonly class ClientBodySize
{
    private const string CONTENT_LENGTH = 'content-length';

    private function __construct(
        public ?int $request,
        public ?int $response,
    ) {}

    public static function of(AsyncContext $context): self
    {
        return new self(self::uploaded($context), self::declared($context));
    }

    private static function uploaded(AsyncContext $context): ?int
    {
        /** @var mixed $uploaded */
        $uploaded = $context->getInfo('size_upload');

        return \is_int($uploaded) || \is_float($uploaded) ? \max(0, (int) $uploaded) : null;
    }

    private static function declared(AsyncContext $context): ?int
    {
        /** @var mixed $lengths */
        $lengths = $context->getHeaders()[self::CONTENT_LENGTH] ?? null;
        /** @var mixed $length */
        $length = \is_array($lengths) ? $lengths[0] ?? null : null;

        if (!\is_string($length) || !\ctype_digit($length)) {
            return null;
        }

        return (int) $length;
    }
}
