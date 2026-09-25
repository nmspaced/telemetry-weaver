<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceRelations;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * The context an operation runs in, whether or not it records a span.
 *
 * Both openers use it. {@see SpanOpener} creates the span as a child of this context, and
 * {@see ContextOnlyOpener} activates the context itself. The rules are kept in one place
 * because they are not about spans. They decide which trace a boundary continues and which
 * baggage the work inside it carries, and the answers must not change when a span is
 * switched off.
 *
 * @internal
 */
final readonly class OperationParent
{
    public static function resolve(SpanOptions $options, ContextInterface $ambient): ContextInterface
    {
        return self::withBaggage(self::parentOf($options->relations, $ambient), $options->baggage);
    }

    /**
     * No incoming trace continues whatever is running; one that arrived replaces it, valid
     * or not. An invalid one is the whole point of the distinction: a boundary that carried
     * nothing starts a new trace, where inheriting the ambient context would attach a fresh
     * request to the remains of the last one.
     */
    private static function parentOf(TraceRelations $relations, ContextInterface $ambient): ContextInterface
    {
        $incoming = $relations->parent;

        if ($incoming === null) {
            return $ambient;
        }

        // An explicit boundary without a usable SDK context, including RootTrace after
        // a propagation failure, must not adopt the ambient span.
        return $incoming instanceof OtelIncomingTrace ? $incoming->context : Context::getRoot();
    }

    /**
     * Baggage goes into the context the operation runs in, so every outgoing call made
     * inside the operation propagates it. With a span, that context is also where the
     * span is created, so a sampler that reads baggage sees this operation's entries and
     * not only the parent's.
     *
     * @param array<non-empty-string, string> $entries
     */
    private static function withBaggage(ContextInterface $parent, array $entries): ContextInterface
    {
        if ($entries === []) {
            return $parent;
        }

        $builder = Baggage::fromContext($parent)->toBuilder();

        foreach ($entries as $key => $value) {
            $builder->set($key, $value);
        }

        return $builder->build()->storeInContext($parent);
    }
}
