<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;

/** A response propagator that writes the span id it receives, standing in for `traceresponse`. */
// @mago-expect analysis:experimental-usage — mirrors the experimental upstream contract on purpose
final class RecordingResponsePropagator implements ResponsePropagatorInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?\Throwable $failure = null,
    ) {}

    /**
     * @throws \Throwable whatever this double was built to throw
     */
    #[\Override]
    public function inject(
        mixed &$carrier,
        ?PropagationSetterInterface $setter = null,
        ?ContextInterface $context = null,
    ): void {
        ++$this->calls;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (!\is_array($carrier) || $context === null) {
            return;
        }

        $spanContext = Span::fromContext($context)->getContext();

        if (!$spanContext->isValid()) {
            return;
        }

        $carrier['traceresponse'] = \sprintf('00-%s-%s-01', $spanContext->getTraceId(), $spanContext->getSpanId());
    }
}
