<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpProtocol;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The `sdk.*` combinations the bundle refuses to compile.
 *
 * Separate from {@see SdkComponentIds}, which turns an id into a reference: this one answers
 * a different question, and the answer is always "this configuration describes a pipeline that
 * will not be built". Silence would be worse than a failed compile — the file would read as a
 * promise that something was configured.
 *
 * @internal
 */
final readonly class SdkComponentRules
{
    /**
     * Keys describing something a provider builds for itself.
     *
     * @var list<non-empty-string>
     */
    private const array INSIDE_A_PROVIDER = ['exporter', 'sampler', 'id_generator', 'span_processors', 'views'];

    /**
     * @param list<non-empty-string> $signals
     *
     * @throws InvalidArgumentException
     */
    public static function rejectIgnoredKeys(ContainerBuilder $container, array $signals): void
    {
        foreach ($signals as $signal) {
            if ($container->getParameter('open_telemetry.sdk.' . $signal . '.provider') === null) {
                continue;
            }

            self::rejectKeysInsideThatProvider($container, $signal);
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
     * @param non-empty-string $signal
     *
     * @throws InvalidArgumentException
     */
    private static function rejectKeysInsideThatProvider(ContainerBuilder $container, string $signal): void
    {
        foreach (self::INSIDE_A_PROVIDER as $key) {
            if (!self::isConfigured($container, 'open_telemetry.sdk.' . $signal . '.' . $key)) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf(
                'sdk.%1$s.%2$s is ignored when sdk.%1$s.provider is set; configure it inside the provider.',
                $signal,
                $key,
            ));
        }
    }

    private static function isConfigured(ContainerBuilder $container, string $parameter): bool
    {
        if (!$container->hasParameter($parameter)) {
            return false;
        }

        $value = $container->getParameter($parameter);

        return $value !== null && $value !== [];
    }
}
