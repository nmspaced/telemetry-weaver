<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Doctrine\DBAL\Driver\Middleware;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\DependencyInjection\SignalSwitch;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineTelemetry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the DBAL middleware, or does not register anything at all.
 *
 * A pass rather than an entry in services.php, because two of the three conditions
 * cannot be asked there: whether `doctrine/dbal` is available (it is a `suggest`, and a
 * definition whose class does not exist has no business being in the container), and
 * what the two `enabled` flags resolved to.
 *
 * When both signals are off the middleware is absent rather than inert, so the cost of
 * the instrumentation is exactly zero. With one signal on and the other off the
 * middleware is still wired, and the disabled half is expressed by what gets injected:
 * `open_telemetry.doctrine.telemetry` resolves its span opener and its meter to no-ops,
 * so no flag has to travel into the instrumentation.
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
            ->setArgument('$recordStatements', SignalSwitch::on(
                $container,
                'open_telemetry.instrumentation.doctrine.query_text',
            ))
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
}
