<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ParentContext::class)]
final class HttpParentContextTest extends HttpTelemetryTestCase
{
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    /** @throws \Throwable */
    #[Test]
    public function aTraceparentContinuesTheCallersTrace(): void
    {
        $this->handle($this->request(static fn(): Response => new Response(), headers: [
            'traceparent' => self::TRACEPARENT,
        ]));

        $span = $this->exportedSpan();
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $span->getContext()->getTraceId());
        self::assertSame('b7ad6b7169203331', $span->getParentContext()->getSpanId());
    }

    /**
     * Extraction is based on the root context, not the current one. Without
     * that, a request with no traceparent would adopt whatever third-party
     * instrumentation left on top of the stack.
     *
     * @throws \Throwable
     */
    #[Test]
    public function withoutATraceparentAnAmbientSpanIsNotInherited(): void
    {
        $leaked = $this->leak('left-behind-by-someone-else');

        $this->handle($this->request(static fn(): Response => new Response()));

        $span = $this->exportedSpan();
        self::assertFalse($span->getParentContext()->isValid());
        self::assertNotSame($leaked->getContext()->getTraceId(), $span->getContext()->getTraceId());

        $leaked->end();
    }
}
