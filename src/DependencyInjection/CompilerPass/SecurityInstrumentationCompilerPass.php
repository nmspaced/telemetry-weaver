<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributesSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Adds the authenticated user to the server span, when `http_server` asks for it.
 *
 * Reads `security.untracked_token_storage`: the tracked storage marks the session as used and
 * makes every response behind a lazy firewall uncacheable.
 */
final readonly class SecurityInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/security-core', TokenStorageInterface::class)
            ->needs('security.untracked_token_storage')
            ->instruments('http_server');

        if ($gate->isClosed() || !$container->hasDefinition(RequestTraceRegistry::class)) {
            return;
        }

        $recordUserId = $container->getParameter('open_telemetry.instrumentation.http_server.record_user_id') === true;
        $recordRoles =
            $container->getParameter('open_telemetry.instrumentation.http_server.record_user_roles') === true;

        if (!$recordUserId && !$recordRoles) {
            return;
        }

        $container
            ->register(UserAttributes::class, UserAttributes::class)
            ->setArgument('$tokenStorage', new Reference('security.untracked_token_storage'))
            ->setArgument('$recordUserId', $recordUserId)
            ->setArgument('$recordUserRoles', $recordRoles);

        $container
            ->register(UserAttributesSubscriber::class, UserAttributesSubscriber::class)
            ->setArgument('$requestTraces', new Reference(RequestTraceRegistry::class))
            ->setArgument('$users', new Reference(UserAttributes::class))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->addTag('kernel.event_subscriber');
    }
}
