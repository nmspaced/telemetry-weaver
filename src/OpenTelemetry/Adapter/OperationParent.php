<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceRelations;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * Resolves the context an operation runs in, for both openers, so the incoming trace and
 * baggage behave the same whether or not a span is recorded.
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
     * An incoming trace, even an invalid one, replaces the ambient context; no incoming trace
     * continues the ambient one.
     */
    private static function parentOf(TraceRelations $relations, ContextInterface $ambient): ContextInterface
    {
        $incoming = $relations->parent;

        if ($incoming === null) {
            return $ambient;
        }

        return $incoming instanceof OtelIncomingTrace ? $incoming->context : Context::getRoot();
    }

    /**
     * Adds the entries to the context the operation runs in, before any span is created.
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
