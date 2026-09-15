<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Reads propagation headers straight out of the HeaderBag.
 *
 * The carrier used to be a lowercased copy of every header on the request,
 * rebuilt per request so that a propagator could read two or three keys from
 * it. On the server hot path that is a full array allocation for nothing:
 * HeaderBag already keys its storage lowercased and already answers get() in
 * constant time, so the bag can be the carrier as it stands.
 */
final readonly class RequestHeadersGetter implements PropagationGetterInterface
{
    /**
     * @param HeaderBag $carrier
     *
     * @return list<string>
     */
    #[\Override]
    public function keys($carrier): array
    {
        return \array_keys($carrier->all());
    }

    /**
     * @param HeaderBag $carrier
     */
    #[\Override]
    public function get($carrier, string $key): ?string
    {
        return $carrier->get($key);
    }
}
