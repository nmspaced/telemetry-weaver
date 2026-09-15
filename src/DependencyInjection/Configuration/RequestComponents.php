<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Instrumentation components whose signals fire on the request path: HTTP in, HTTP out,
 * console commands and Doctrine queries.
 *
 * @internal
 */
final class RequestComponents
{
    /**
     * @var list<string>
     */
    private const array DEFAULT_EXCLUDED_HTTP_PATHS = [
        '/_profiler',
        '/_wdt',
        '/health',
    ];

    /**
     * Workers that are never traced as commands, whatever the configuration says.
     *
     * Their console span would stay open for the life of the process, which is wrong in
     * four separate ways: the span is never exported, because the SDK exports on end and
     * the end comes when the process exits; every per-message span becomes its child, so
     * one unbounded trace replaces one bounded trace per message; `only_with_parent`
     * loses its meaning, because an always-valid active span makes the transport's idle
     * BEGIN/SELECT/COMMIT look like work inside a unit of work; and the duration
     * histogram gets one sample per process instead of one per run.
     *
     * Nothing is lost by excluding them. The work a worker actually does is already
     * traced per message by the Messenger instrumentation, which is the bounded version
     * of the same information.
     *
     * @var list<string>
     */
    private const array LONG_RUNNING_CONSOLE_COMMANDS = [
        'messenger:consume',
        'messenger:consume-messages',
    ];

    /**
     * Noise a normal application does not want traced, but may legitimately want back:
     * these are replaced when the key is set, unlike the workers above.
     *
     * @var list<string>
     */
    private const array DEFAULT_EXCLUDED_CONSOLE_COMMANDS = [
        'cache:clear',
        'cache:warmup',
        'assets:install',
        'lint:container',
        'lint:yaml',
    ];

    /** @throws \RuntimeException */
    public function httpServer(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component(
            'http_server',
            'Incoming HTTP requests.',
            overridableOption: 'excluded_paths',
        );

        $node
            ->children()
            ->arrayNode('excluded_paths')
            ->performNoDeepMerging()
            ->info('Request path prefixes to skip. A signal can narrow or widen this with its own list.')
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue(self::DEFAULT_EXCLUDED_HTTP_PATHS)
            ->end()
            ->booleanNode('record_client_ip')
            ->info('Record client.address. Off by default: an IP address is personal data in most jurisdictions.')
            ->defaultFalse()
            ->end()
            ->integerNode('record_exception_min_status')
            ->min(400)
            ->max(599)
            ->info(
                'Lowest response status at which the exception is recorded as a span event. Whether the span is errored is not configurable: the conventions say >= 500.',
            )
            ->defaultValue(500)
            ->end()
            ->end();

        return $node;
    }

    /** @throws \RuntimeException */
    public function httpClient(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component(
            'http_client',
            'Outgoing HTTP requests.',
            overridableOption: 'excluded_hosts',
        );

        $node
            ->children()
            ->arrayNode('excluded_hosts')
            ->performNoDeepMerging()
            ->info('Remote hosts to skip — typically the collector itself, to keep telemetry from tracing telemetry.')
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([])
            ->end()
            ->end();

        return $node;
    }

    /** @throws \RuntimeException */
    public function console(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component('console', 'Console commands.');

        $node
            ->children()
            ->arrayNode('excluded_commands')
            ->performNoDeepMerging()
            ->info(
                'Command names to skip. Setting this replaces the defaults, except for the Messenger workers: those are always excluded, because a span covering the whole worker process is never exported, swallows every message into one trace, and defeats doctrine.only_with_parent. Their work is traced per message by the Messenger instrumentation instead.',
            )
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([...self::LONG_RUNNING_CONSOLE_COMMANDS, ...self::DEFAULT_EXCLUDED_CONSOLE_COMMANDS])
            ->validate()
            ->always(self::withLongRunningCommands(...))
            ->end()
            ->end()
            ->end();

        return $node;
    }

    /**
     * @param list<string> $commands
     *
     * @return list<string>
     */
    private static function withLongRunningCommands(array $commands): array
    {
        return \array_values(\array_unique([
            ...self::LONG_RUNNING_CONSOLE_COMMANDS,
            ...$commands,
        ]));
    }

    /** @throws \RuntimeException */
    public function doctrine(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component('doctrine', 'Doctrine DBAL queries.');

        $node
            ->children()
            ->booleanNode('query_text')
            ->info(
                'Record the statement as db.query.text. Off by default: raw SQL may carry literals and personal data.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('only_with_parent')
            ->info(
                'Create query spans only inside an existing trace. Keeps orphan spans of background polling out; metrics are recorded either way.',
            )
            ->defaultTrue()
            ->end()
            ->booleanNode('transactions')
            ->info(
                'Record BEGIN, COMMIT and ROLLBACK as operations of their own, on both signals, named by db.operation.name. Nested levels are savepoint statements and are traced as statements.',
            )
            ->defaultTrue()
            ->end()
            ->end();

        return $node;
    }
}
