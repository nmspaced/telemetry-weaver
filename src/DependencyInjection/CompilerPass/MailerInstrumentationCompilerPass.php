<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\DecoratedService;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Mailer\MailerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Mailer\TraceableMailTransport;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * One CLIENT span per transport call, by decorating the `mailer.transports` aggregate.
 *
 * Individual transports have no service ids; the aggregate is what both `Mailer::send()`
 * and the Messenger handler call.
 */
final readonly class MailerInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string TRANSPORTS_ID = 'mailer.transports';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/mailer', TransportInterface::class)
            ->needs(self::TRANSPORTS_ID)
            ->instruments('mailer');

        if ($gate->isClosed()) {
            return;
        }

        $container
            ->register(MailerTelemetry::class, MailerTelemetry::class)
            ->setArgument('$telemetry', new Reference('open_telemetry.mailer.telemetry'))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->setArgument(
                '$recordSubject',
                $container->getParameter('open_telemetry.instrumentation.mailer.record_subject'),
            )
            ->setArgument('$buckets', new Reference('open_telemetry.mailer.buckets'));

        $innerId = DecoratedService::innerId(self::TRANSPORTS_ID);

        $container
            ->register(DecoratedService::id(self::TRANSPORTS_ID), TraceableMailTransport::class)
            ->setDecoratedService(self::TRANSPORTS_ID, $innerId)
            ->setArgument('$delegate', new Reference($innerId))
            ->setArgument('$telemetry', new Reference(MailerTelemetry::class));
    }
}
