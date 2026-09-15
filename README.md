# 🧵 Telemetry Weaver

**OpenTelemetry for Symfony applications whose PHP process can outlive a request.**

English · [Русский](README.ru.md)

Telemetry Weaver adds traces, metrics and logs to **FrankenPHP, RoadRunner, Symfony Messenger and request-based PHP** while treating lifecycle and failure isolation as first-class concerns. It keeps request/message context isolated in long-running workers and bounds the amount of application time telemetry is allowed to consume.

**PHP 8.4+ · Symfony 8.1+ · OpenTelemetry PHP SDK · MIT**

## Why Telemetry Weaver

- **Worker-first lifecycle.** HTTP requests, Messenger messages and Console commands are explicit execution boundaries. State owned by one unit of work is not allowed to leak into the next one.
- **Fail-open instrumentation.** Telemetry failures do not replace your return value or the original business exception.
- **Bounded export cost.** Batch queues, one boundary deadline, destination-aware shares and cooldowns limit the effect of a slow or unavailable Collector.
- **OpenTelemetry-native.** Sampling, propagation, aggregation and OTLP remain the responsibility of the official PHP SDK. The bundle adds Symfony lifecycle, instrumentation and resilience.
- **Conservative data capture.** SQL text, client IPs and mail subjects are opt-in. Span attributes are not copied into metric labels.
- **Replaceable SDK pipeline.** HTTP/gRPC transports, exporters and providers can be replaced through Symfony DI when the defaults are not enough.

## Installation

For OTLP over HTTP/protobuf:

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

Register the bundle:

```php
// config/bundles.php
return [
    // ...
    Nmspaced\TelemetryWeaver\TelemetryWeaverBundle::class => ['all' => true],
];
```

Optional integrations activate when their dependencies are installed. Doctrine instrumentation requires DBAL 4. OTLP log export requires Monolog and Symfony MonologBundle.

For OTLP/gRPC also install the OpenTelemetry gRPC transport and `ext-grpc`:

```bash
composer require open-telemetry/transport-grpc
```

## Recommended production topology

Prefer a **local or nearby OpenTelemetry Collector / Grafana Alloy** and let it own remote queues, retries, credentials and backend-specific routing:

```text
PHP worker
   │ short, bounded OTLP handoff
   ▼
Collector / Alloy on the same host, sidecar or local network
   │ queues / retries / batching / routing
   ▼
remote observability backend(s)
```

This is the same agent pattern recommended by OpenTelemetry Collector. Telemetry Weaver protects the application-side handoff, but it is deliberately **not** a durable delivery queue.

A good starting environment for a worker using a local OTLP/HTTP receiver is:

```dotenv
OTEL_SERVICE_NAME=orders-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=backend,deployment.environment.name=production

# Telemetry Weaver creates the providers. Do not bootstrap a second SDK pipeline.
OTEL_PHP_AUTOLOAD_ENABLED=false
OTEL_PROPAGATORS=tracecontext,baggage

OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none

OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_TIMEOUT=500

# Keep the parent's sampling decision; sample 10% of new root traces.
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1

# Batch spans: Telemetry Weaver disables processor auto-flush and drains at boundaries.
OTEL_PHP_TRACES_PROCESSOR=batch
OTEL_BSP_SCHEDULE_DELAY=1000
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512

# Used as the default boundary flush cadence for metrics.
OTEL_METRIC_EXPORT_INTERVAL=15000
```

And a small Symfony configuration:

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    instrumentation:
        http_client:
            # Use the hostname your application actually calls.
            excluded_hosts: ['127.0.0.1', 'localhost', 'otel-collector', 'alloy']

    sdk:
        export:
            flush_timeout_ms: 1000
            failure_cooldown_ms: 30000
            max_retries: 0
```

The values above are starting points, not universal limits. Tune sampling and queue sizes for the volume **per worker**, and keep the Collector close enough that a 250–500 ms OTLP timeout is already generous in normal operation.

More examples:

- [worker OTLP/HTTP environment](config/examples/worker.env)
- [FPM environment](config/examples/fpm.env)
- [gRPC environment](config/examples/grpc.env)
- [split signal endpoints](config/examples/split-endpoints.env)
- [worker YAML](config/examples/worker.yaml)
- [FPM YAML](config/examples/fpm.yaml)

See [production configuration](docs/en/production-configuration.md) before enabling FPM request metrics or exporting directly to a remote backend.

## Lifecycle in one minute

Telemetry Weaver separates PHP-process, Symfony-container and execution lifetimes:

```text
PHP-FPM
request
  └─ provider lifecycle
       └─ shutdown

FrankenPHP / RoadRunner shared worker
worker
  ├─ request A ─ forceFlush
  ├─ request B ─ forceFlush
  └─ worker shutdown ─ shutdown

Messenger
worker
  ├─ message A ─ forceFlush
  ├─ message B ─ forceFlush
  └─ worker shutdown ─ shutdown
```

`forceFlush()` drains a long-lived pipeline without destroying it. `shutdown()` is terminal. Owned spans/scopes/measurements are cleaned up at the execution boundary so that the next request or message cannot inherit state from the previous one.

See [Architecture and lifecycle](docs/en/architecture.md).

## Resilient export

The default OTLP path is intentionally opinionated:

- spans and logs are queued instead of exporting from `span->end()` / log emission;
- the whole execution boundary has one `flush_timeout_ms` deadline;
- time is shared between **destinations** (`scheme://host:port`), not blindly between signals;
- traces/logs/metrics going to the same Collector share one failure domain;
- a timed-out destination is refused for the rest of the boundary while independent destinations can still run;
- unused time from a fast destination remains available to the following ones;
- very small leftover allowances are not used for network calls;
- cooldown is started only after a sufficiently conclusive timeout;
- boundary sends do not synchronously retry by default.

Example:

```text
traces ─┐
logs   ─┴─→ collector-a:4318  (hung)

metrics ──→ collector-b:4318  (healthy)
```

The hung Collector cannot consume a fresh timeout for every signal and cannot starve the independent metrics destination indefinitely.

See [Export resilience and budgets](docs/en/export-resilience.md).

## Built-in instrumentation

| Component | Telemetry |
|---|---|
| HTTP server | server spans, route templates, duration and HTTP metrics |
| Symfony HttpClient | client spans, propagation, duration, lazy responses and streaming |
| Doctrine DBAL | query spans, query summaries, duration, transaction boundaries and errors |
| Messenger | dispatch/send/process spans, propagation and send/process metrics |
| Console | command spans, duration and exit code; worker commands are traced per message |
| Cache | pool operations, duration and hit/miss measurements |
| Serializer | serialization operations and duration |
| Mailer | transport send spans and duration |
| Scheduler | scheduled task execution inside message processing |
| Monolog | trace/span correlation and optional OTLP log export |
| PHP runtime | long-lived worker memory and uptime |

The bundle targets Semantic Conventions schema `1.44.0`. Potentially sensitive values such as SQL text, client IP and mail subjects are disabled by default where the bundle exposes them.

See [Instrumentation notes](docs/en/instrumentation.md).

## Application API

`Telemetry` is available through Symfony autowiring.

### Trace a business operation

```php
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    public function __construct(private Telemetry $telemetry) {}

    public function place(string $orderId, \Closure $work): mixed
    {
        return $this->telemetry->trace(
            'order.place',
            function (Span $span) use ($orderId, $work): mixed {
                $span->attribute('app.order.id', $orderId);

                return $work();
            },
        );
    }
}
```

The callback runs exactly once. The span is closed automatically, including on exceptions, and telemetry failures do not replace the original application result.

### Trace and measure the same operation

```php
use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class PaymentTelemetry
{
    private Duration $duration;

    public function __construct(private Telemetry $telemetry)
    {
        $this->duration = $telemetry->metrics()->duration(
            'app.payment.duration',
            unit: DurationUnit::Seconds,
            boundaries: [0.01, 0.05, 0.1, 0.5, 1, 5],
        );
    }

    /** @param \Closure(): bool $charge */
    public function charge(\Closure $charge): bool
    {
        return $this->telemetry
            ->operation('payment.charge')
            ->duration($this->duration, attributes: ['app.payment.method' => 'card'])
            ->run(function (OperationContext $context) use ($charge): bool {
                $accepted = $charge();
                if (!$accepted) {
                    $context->fail('payment.declined');
                }

                return $accepted;
            });
    }
}
```

`fail()` marks both the span and the measurement without inventing an exception. Span attributes and metric attributes are separate on purpose: do not put IDs, payloads or other unbounded values into metric labels.

Counters, histograms, observable gauges/up-down counters, `currentSpan()` and `TelemetryFactory::scope()` are also available.

## Replace transport, exporter or provider

The defaults are safe, not mandatory. Symfony DI can replace increasingly large parts of the signal pipeline:

```text
default
provider → resilient exporter → budget-aware OTLP transport

transport override
provider → resilient exporter → your transport

exporter override
provider → resilient wrapper → your exporter

provider override
your provider (Telemetry Weaver only adopts its boundary lifecycle)
```

HTTP and gRPC transport families can be replaced independently. The deeper the override, the fewer guarantees the default Weaver pipeline can provide.

See [SDK customization](docs/en/sdk-customization.md) for the guarantee matrix and examples.

## Testing

Use `InMemoryTelemetry` in application tests; no Collector is required:

```php
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;

$telemetry = InMemoryTelemetry::create();

try {
    $workflow = new OrderWorkflow($telemetry);
    $result = $workflow->place('order-42', static fn(): bool => true);

    assert($result === true);
    assert($telemetry->spans()[0]->getName() === 'order.place');
} finally {
    $telemetry->shutdown();
}
```

## Documentation

- [Production configuration](docs/en/production-configuration.md)
- [Architecture and lifecycle](docs/en/architecture.md)
- [Export resilience and budgets](docs/en/export-resilience.md)
- [Instrumentation notes](docs/en/instrumentation.md)
- [SDK customization](docs/en/sdk-customization.md)
- [Minimal bundle configuration](config/minimal_config.yaml)
- [Full configuration reference](config/example_config.yaml)
- [OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/)

Licensed under **MIT**.
