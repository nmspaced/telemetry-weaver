<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Doctrine\DBAL\Driver\Middleware;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\DependencyInjection\SignalSwitch;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\QueryText;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the DBAL middleware when `doctrine/dbal` is available and either signal is on.
 *
 * With both signals off the middleware is not registered at all; with one off, the disabled
 * half is a no-op injected through `open_telemetry.doctrine.telemetry`.
 */
final readonly class DoctrineInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string MIDDLEWARE_TAG = 'doctrine.middleware';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('doctrine/dbal', Middleware::class)
            ->instruments('doctrine');

        if ($gate->isClosed()) {
            return;
        }

        $container
            ->register(DoctrinePolicy::class, DoctrinePolicy::class)
            ->setArgument(
                '$queryText',
                QueryText::from(self::string($container->getParameter(
                    'open_telemetry.instrumentation.doctrine.query_text',
                ))),
            )
            ->setArgument('$onlyWithParent', SignalSwitch::on(
                $container,
                'open_telemetry.instrumentation.doctrine.only_with_parent',
            ))
            ->setArgument('$recordTransactions', SignalSwitch::on(
                $container,
                'open_telemetry.instrumentation.doctrine.transactions',
            ));

        $container
            ->register(DoctrineTelemetry::class, DoctrineTelemetry::class)
            ->setArgument('$telemetry', new Reference('open_telemetry.doctrine.telemetry'))
            ->setArgument('$policy', new Reference(DoctrinePolicy::class))
            ->setArgument('$buckets', new Reference('open_telemetry.doctrine.buckets'));

        $container
            ->register(DoctrineMiddleware::class, DoctrineMiddleware::class)
            ->setArgument('$doctrineTelemetry', new Reference(DoctrineTelemetry::class))
            ->addTag(self::MIDDLEWARE_TAG, ['priority' => 0]);
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            return QueryText::Sanitized->value;
        }

        return $value;
    }
}
