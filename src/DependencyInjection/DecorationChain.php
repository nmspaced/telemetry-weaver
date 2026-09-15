<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Which service a decorator will actually be handed.
 *
 * Not the decorated service, whenever something else decorates it too. Symfony resolves
 * a decoration chain by priority — higher is applied earlier and so ends up further in —
 * and a pass that reads the decorated service's own class is reading the wrong end of
 * that chain whenever another decorator lands between the two.
 *
 * The difference is not academic. FrameworkBundle's `debug.serializer` decorates
 * `serializer` at the default priority in dev, and it implements five of the serializer
 * interfaces but neither context-aware one. A decoration check that asked about
 * `serializer` saw a class implementing all seven, approved the wrapping, and the
 * container then fataled on a constructor intersection type at first use — in the error
 * controller, which is where a fatal is least recoverable.
 */
final readonly class DecorationChain
{
    /**
     * The id of the service that will sit directly inside a decorator of `$decoratedId`
     * registered at `$priority`.
     *
     * Decorators above that priority are inside it; the nearest of them is the one with
     * the lowest such priority. With none, the decorated service itself is the neighbour.
     */
    public static function innerId(ContainerBuilder $container, string $decoratedId, int $priority): string
    {
        $innerId = $decoratedId;
        $innerPriority = null;

        foreach ($container->getDefinitions() as $candidateId => $definition) {
            $decorated = $definition->getDecoratedService();

            if ($decorated === null || ($decorated[0] ?? null) !== $decoratedId) {
                continue;
            }

            /** @var mixed $candidatePriority */
            $candidatePriority = $decorated[2] ?? 0;

            if (!\is_int($candidatePriority) || $candidatePriority <= $priority) {
                continue;
            }

            if ($innerPriority === null || $candidatePriority < $innerPriority) {
                $innerPriority = $candidatePriority;
                $innerId = $candidateId;
            }
        }

        return $innerId;
    }
}
