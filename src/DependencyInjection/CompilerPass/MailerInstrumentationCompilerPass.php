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
 * One CLIENT span per transport invocation, by decorating the transport aggregate.
 *
 * `mailer.transports` rather than the individual transports, because the individual
 * transports have no service ids of their own — FrameworkBundle builds them from DSNs
 * inside the aggregate's factory. The aggregate is also the single boundary both ways
 * of sending pass through: a synchronous `Mailer::send()` and Messenger's
 * `MessageHandler` both end up calling it, so the span covers the transport work and
 * not the queueing that may precede it.
 *
 * Which transport actually ran is read from the message, not from the decorated
 * service — the decorated service is always the aggregate.
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
            );

        $innerId = DecoratedService::innerId(self::TRANSPORTS_ID);

        $container
            ->register(DecoratedService::id(self::TRANSPORTS_ID), TraceableMailTransport::class)
            ->setDecoratedService(self::TRANSPORTS_ID, $innerId)
            ->setArgument('$delegate', new Reference($innerId))
            ->setArgument('$telemetry', new Reference(MailerTelemetry::class));
    }
}
