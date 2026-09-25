<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds which service a decorator will actually wrap.
 *
 * Another decorator may sit in between (for example `debug.serializer`), and its class is
 * the one that has to satisfy the decorator's constructor.
 */
final readonly class DecorationChain
{
    /** The id directly inside a decorator of `$decoratedId` registered at `$priority`. */
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
