<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\DependencyInjection\Configuration\BackgroundComponents;
use Nmspaced\TelemetryWeaver\DependencyInjection\Configuration\RequestComponents;
use Nmspaced\TelemetryWeaver\DependencyInjection\Configuration\RuntimeConfiguration;
use Nmspaced\TelemetryWeaver\DependencyInjection\Configuration\SdkComponentsConfiguration;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

return new class {
    /** @throws \RuntimeException */
    public function __invoke(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();

        $root
            ->children()
            ->booleanNode('enabled')
            ->info('Enable OpenTelemetry instrumentation provided by the bundle.')
            ->defaultTrue()
            ->end()
            ->end();

        $root
            ->append(RuntimeConfiguration::node())
            ->append($this->sdk())
            ->append($this->scope())
            ->append($this->instrumentation())
            ->append($this->traces())
            ->append($this->metrics())
            ->append($this->logs())
            ->append($this->diagnostics());
    }

    /** @throws \RuntimeException */
    private function sdk(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('sdk');

        $node
            ->addDefaultsIfNotSet()
            ->info('OpenTelemetry SDK bootstrap and resource configuration.')
            ->children()
            ->arrayNode('resource_attributes')
            ->info('Resource attributes added by the bundle and merged with OTEL_RESOURCE_ATTRIBUTES.')
            ->normalizeKeys(false)
            ->useAttributeAsKey('name')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('exporter_otlp_headers')
            ->info(
                'Headers merged over OTEL_EXPORTER_OTLP_HEADERS; these win on a collision, and a null value drops a header the variable set. OTLP transports only.',
            )
            ->normalizeKeys(false)
            ->useAttributeAsKey('name')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('export')
            ->addDefaultsIfNotSet()
            ->info('Boundary export budget, cooldown and OTLP retry behaviour.')
            ->children()
            ->integerNode('flush_timeout_ms')
            ->min(1)
            ->defaultValue(1000)
            ->info(
                'Total boundary budget across traces, logs and metrics. OTLP batches use the remaining timeout and no retries. Custom transports must honour timeouts.',
            )
            ->end()
            ->integerNode('failure_cooldown_ms')
            ->min(0)
            ->defaultValue(30_000)
            ->info('Pause after a failed signal flush; shutdown makes a final attempt within the shared budget.')
            ->end()
            ->integerNode('max_retries')
            ->min(0)
            ->info(
                'Retries after a failed export attempt. 0 disables retrying: every retry sleeps in the calling process, so a collector that is down would block the application.',
            )
            ->defaultValue(0)
            ->end()
            ->integerNode('retry_delay_ms')
            ->min(0)
            ->info(
                'Base delay between retries in milliseconds; doubled on each attempt. Ignored when max_retries is 0.',
            )
            ->defaultValue(100)
            ->end()
            ->end()
            ->end()
            ->append(SdkComponentsConfiguration::otlp())
            ->append(SdkComponentsConfiguration::traces())
            ->append(SdkComponentsConfiguration::metrics())
            ->append(SdkComponentsConfiguration::signal('logs'))
            ->end();

        return $node;
    }

    /** @throws \RuntimeException */
    private function scope(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('scope');

        $node
            ->addDefaultsIfNotSet()
            ->info('Instrumentation scope shared by tracers and meters created by the bundle.')
            ->children()
            ->stringNode('name')
            ->info('Instrumentation scope name.')
            ->defaultValue('nmspaced/telemetry-weaver')
            ->cannotBeEmpty()
            ->end()
            ->stringNode('version')
            ->info('Instrumentation scope version; null means unspecified.')
            // Explicit null is unset rather than validated: cannotBeEmpty()
            // rejects it, which made the documented `version: null` invalid.
            ->beforeNormalization()
            ->ifNull()
            ->thenUnset()
            ->end()
            ->defaultNull()
            ->cannotBeEmpty()
            ->end()
            ->stringNode('schema_url')
            ->info('OpenTelemetry semantic convention schema URL.')
            ->defaultValue(Version::VERSION_1_44_0->url())
            ->cannotBeEmpty()
            ->end()
            ->end();

        return $node;
    }

    private function traces(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('traces');

        $node
            ->addDefaultsIfNotSet()
            ->info('Distributed tracing. What gets instrumented lives under "instrumentation".')
            ->children()
            ->booleanNode('enabled')
            ->info('Master switch for tracing. With this off no component produces spans, whatever its own key says.')
            ->defaultTrue()
            ->end()
            ->end();

        return $node;
    }

    /** @throws \RuntimeException */
    private function metrics(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('metrics');

        $node
            ->addDefaultsIfNotSet()
            ->info('OpenTelemetry metrics. What gets instrumented lives under "instrumentation".')
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Master switch for metrics. With this off no component records measurements, whatever its own key says.',
            )
            ->defaultTrue()
            ->end()
            ->integerNode('flush_interval_ms')
            ->min(1)
            ->info(
                'Minimum interval between metric flushes at an execution boundary. null follows OTEL_METRIC_EXPORT_INTERVAL, which is what the SDK exports on anyway. Traces and logs are not affected: they are drained on OTEL_BSP_SCHEDULE_DELAY and OTEL_BLRP_SCHEDULE_DELAY.',
            )
            // An explicit null has to be unset rather than validated: an integer
            // node rejects it, and writing the default out is how a reference
            // file documents that the default means "ask the SDK".
            ->beforeNormalization()
            ->ifNull()
            ->thenUnset()
            ->end()
            ->defaultNull()
            ->end()
            ->end();

        return $node;
    }

    /**
     * One node per component instead of two. The old tree described every component
     * twice — once under `traces`, once under `metrics` — and the two halves had to be
     * kept in step by hand, which is where most of the configuration defects came from.
     *
     * Each component takes `traces` and `metrics` as booleans. Where a signal needs its
     * own options, the same key accepts the long form `{enabled: bool, ...}`; the short
     * form is normalised into it. That is what keeps the common case one line while
     * leaving room for the one case that genuinely differs per signal: excluding
     * /health from traces but keeping it in metrics, because a health check is noise in
     * a trace and load in a metric.
     *
     * @throws \RuntimeException
     */
    private function instrumentation(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('instrumentation');
        $requestComponents = new RequestComponents();
        $backgroundComponents = new BackgroundComponents();

        $node
            ->addDefaultsIfNotSet()
            ->info('What gets instrumented. Each component enables its signals and carries its own options.')
            ->append($requestComponents->httpServer())
            ->append($requestComponents->httpClient())
            ->append($requestComponents->console())
            ->append($requestComponents->doctrine())
            ->append($backgroundComponents->messenger())
            ->append($backgroundComponents->serializer())
            ->append($backgroundComponents->scheduler())
            ->append($backgroundComponents->mailer())
            ->append($backgroundComponents->cache())
            ->append($backgroundComponents->runtime());

        return $node;
    }

    private function logs(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('logs');

        $node
            ->addDefaultsIfNotSet()
            ->info('OpenTelemetry log integration.')
            ->append($this->enabledNode(
                'correlation',
                'Trace context correlation for application logs.',
                'Inject active trace and span identifiers into log records.',
            ))
            ->append($this->logExport());

        return $node;
    }

    /**
     * Log export needs symfony/monolog-bundle: the handler is inserted into Monolog's
     * own stack, which is the only place a Symfony application's log records all pass
     * through.
     */
    private function logExport(): ArrayNodeDefinition
    {
        $node = $this->enabledNode(
            'export',
            'OpenTelemetry log exporting.',
            'Export application log records through OpenTelemetry. Off by default: it adds a second destination for every log line, and the OTEL_LOGS_EXPORTER variable has to name somewhere to send them.',
            false,
        );

        $node
            ->children()
            ->enumNode('level')
            ->values(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])
            ->info(
                'Lowest level exported. Not debug by default: shipping every debug line off the box is a bandwidth decision, not a logging one.',
            )
            ->defaultValue('info')
            ->end()
            ->arrayNode('excluded_channels')
            ->performNoDeepMerging()
            ->info(
                'Monolog channels never exported. Put the channel the OTLP transport itself logs to here if it has one: exporting it would describe the export.',
            )
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([])
            ->end()
            ->end();

        return $node;
    }

    private function diagnostics(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('diagnostics');

        $node
            ->addDefaultsIfNotSet()
            ->info('Reporting for telemetry context and instrumentation anomalies.')
            ->children()
            ->booleanNode('enabled')
            ->info('Enable internal diagnostics.')
            ->defaultTrue()
            ->end()
            ->integerNode('detailed_per_process')
            ->info('How many initial anomalies are reported with detailed per-process information.')
            ->min(0)
            ->defaultValue(10)
            ->end()
            ->floatNode('min_interval_seconds')
            ->info('Minimum interval between subsequent diagnostic reports.')
            ->min(0)
            ->defaultValue(60.0)
            ->end()
            ->end();

        return $node;
    }

    private function enabledNode(
        string $name,
        string $info,
        string $enabledInfo,
        bool $defaultEnabled = true,
    ): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition($name);

        $enabled = $node->addDefaultsIfNotSet()->info($info)->children()->booleanNode('enabled')->info($enabledInfo);

        $defaultEnabled ? $enabled->defaultTrue() : $enabled->defaultFalse();

        $enabled->end()->end();

        return $node;
    }
};
