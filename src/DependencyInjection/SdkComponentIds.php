<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpProtocol;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Reads the `sdk.*` service ids an application configured, and refuses the ones that cannot work.
 *
 * Checked at compile time rather than in the config tree, because only the container knows
 * whether a service exists and what it implements — and a wrong id has to fail the build, not
 * the first request that asks for a tracer.
 */
final readonly class SdkComponentIds
{
    /**
     * Keys that a replaced link makes meaningless are an error rather than silently ignored:
     * here, an exporter next to a provider for the same signal.
     *
     * @param list<non-empty-string> $signals
     *
     * @throws InvalidArgumentException
     */
    public static function rejectIgnoredKeys(ContainerBuilder $container, array $signals): void
    {
        foreach ($signals as $signal) {
            if (
                $container->getParameter('open_telemetry.sdk.' . $signal . '.provider') !== null
                && $container->getParameter('open_telemetry.sdk.' . $signal . '.exporter') !== null
            ) {
                throw new InvalidArgumentException(\sprintf(
                    'sdk.%1$s.exporter is ignored when sdk.%1$s.provider is set; configure the exporter inside the provider.',
                    $signal,
                ));
            }
        }
    }

    /**
     * The bundle's transport settings are meaningless once an application's factory covers every
     * protocol family. While one family keeps the bundle's transport they still reach it.
     *
     * @param array<string, Reference> $factories keyed by protocol family
     *
     * @throws InvalidArgumentException
     */
    public static function rejectIgnoredTransportSettings(ContainerBuilder $container, array $factories): void
    {
        if (\count($factories) < \count(OtlpProtocol::FAMILIES)) {
            return;
        }

        if (
            $container->getParameter('open_telemetry.sdk.export.max_retries') !== 0
            || $container->getParameter('open_telemetry.sdk.export.retry_delay_ms') !== 100
            || $container->getParameter('open_telemetry.sdk.exporter_otlp_headers') !== []
        ) {
            throw new InvalidArgumentException(
                "sdk.export.max_retries, sdk.export.retry_delay_ms and sdk.exporter_otlp_headers apply to the bundle's OTLP transport and are ignored when sdk.otlp.transport_factories names a factory for every protocol.",
            );
        }
    }

    /**
     * A service whose class cannot be known before it is built (a factory without a declared
     * class) is accepted; handing the wrong kind then fails as a TypeError where it is used.
     *
     * @param non-empty-string $parameter
     * @param class-string $interface
     *
     * @throws InvalidArgumentException
     */
    public static function reference(ContainerBuilder $container, string $parameter, string $interface): ?Reference
    {
        $id = $container->getParameter($parameter);
        if ($id === null) {
            return null;
        }

        if (!\is_string($id) || $id === '' || !$container->has($id)) {
            throw new InvalidArgumentException(\sprintf(
                '%s names "%s", which is not a service id.',
                $parameter,
                \is_string($id) ? $id : \get_debug_type($id),
            ));
        }

        /** @var mixed $class */
        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());
        if (
            \is_string($class)
            && $container->getReflectionClass($class, false) !== null
            && !\is_a($class, $interface, true)
        ) {
            throw new InvalidArgumentException(\sprintf(
                '%s names "%s" (%s), which does not implement %s.',
                $parameter,
                $id,
                $class,
                $interface,
            ));
        }

        return new Reference($id);
    }
}
