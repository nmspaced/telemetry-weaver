<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Symfony\Component\HttpClient\Response\AsyncContext;

/**
 * Request and response body sizes, read when the response headers arrive without buffering
 * the body. Either may be null; an unknown size is not zero.
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

        if (!\is_int($uploaded) && !\is_float($uploaded)) {
            return null;
        }

        return \max(0, (int) $uploaded);
    }

    private static function declared(AsyncContext $context): ?int
    {
        /** @var mixed $lengths */
        $lengths = $context->getHeaders()[self::CONTENT_LENGTH] ?? null;
        if (!\is_array($lengths)) {
            return null;
        }

        /** @var mixed $length */
        $length = $lengths[0] ?? null;

        if (!\is_string($length) || !\ctype_digit($length)) {
            return null;
        }

        return (int) $length;
    }
}
