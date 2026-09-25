# 🧵 Telemetry Weaver

**OpenTelemetry for Symfony applications whose PHP process outlives the request.**

English · [Русский](README.ru.md)

PHP 8.4+ · Symfony 8.1+ · OpenTelemetry PHP SDK · MIT

Telemetry Weaver wires traces, metrics and logs into Symfony and takes responsibility for the
two things that are hard when the process does not end with the request: **keeping one unit of
work's context out of the next one**, and **keeping telemetry from spending the application's
time**. Sampling, propagation, aggregation and OTLP stay with the official SDK.

## What it does well

- **Worker-mode context containment.** A request, a message and a command are explicit
  execution boundaries. Spans, context activations and measurements that Weaver created are
  closed there; unfinished work is abandoned rather than inherited by the next request. This
  is the whole design, not a feature flag — FrankenPHP, RoadRunner and `messenger:consume` are
  the primary target, request-per-process is the easy case.
- **Failures stay inside telemetry.** An instrumentation that throws does not replace your
  return value, and never replaces the original business exception. Everything the bundle
  fails at is rate-limited into one Monolog channel, together with the SDK's own diagnostics.
- **Export cannot run away with a request.** One deadline covers the whole boundary, whatever
  it has to send. Collectors that time out are skipped for a cooldown; a hung collector does
  not consume the time a healthy one needs. Synchronous retries are off by default because in
  PHP they are sleeps in the worker.
- **A public API without OpenTelemetry's tracing, context or SDK types.** `Telemetry`,
  `Operation`, `Span` and `Metrics` are enough to trace and measure a business operation without
  learning about Context, scopes or exporters. `Metrics` intentionally returns OpenTelemetry's own
  metric instruments, so the full metrics API is there when you need it.
- **Instrumentation that knows Symfony.** HTTP server and client, Doctrine, Messenger,
  Console, Cache, Serializer, Mailer, Scheduler, Monolog and the PHP runtime — wired by
  compiler passes that only activate when the component is actually installed.
- **Conservative by default.** SQL text, client IPs, user identifiers and mail subjects are
  opt-in, one key each. Span attributes are never copied into metric labels, so turning one on
  changes what a trace carries, never how many time series exist.
- **Replaceable where it matters.** Sampler, id generator, span processors, metric views,
  exporters, transports and whole providers can be replaced through Symfony DI. Service ids
  are validated when the container compiles, not on the first request.

## Installation

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

```php
// config/bundles.php
return [
    // ...
    Nmspaced\TelemetryWeaver\TelemetryWeaverBundle::class => ['all' => true],
];
```

Optional instrumentation activates when its component is installed: Doctrine needs DBAL 4,
OTLP log export needs Monolog and MonologBundle, `user.roles` on the server span needs
`symfony/security-core`. For OTLP over gRPC add `open-telemetry/transport-grpc` and `ext-grpc`.

## Getting started

The SDK is configured by environment, the bundle by YAML. A working minimum:

```dotenv
OTEL_SERVICE_NAME=orders-api
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318

# Weaver builds the providers; the SDK must not build a second set.
OTEL_PHP_AUTOLOAD_ENABLED=false
```

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    enabled: true
```

That is a complete configuration. Every component is instrumented, every sensitive capture is
off, and the export budget defaults to one second per boundary with no retries.

> **PHP-FPM and `FRANKENPHP_RESET_KERNEL`: metrics are off by default.** These runtimes build a
> new pipeline for every request, so metrics can only be exported as **delta**
> (`runtime.request_metrics.mode: delta`). A Prometheus-style backend then needs the collector's
> `deltatocumulative` processor, running where every point of a stream reaches the same instance.
> Traces and logs work out of the box. See
> [Request-pipeline metrics](docs/en/configuration.md#export-metrics-from-a-request-per-process-runtime-fpm-frankenphp_reset_kernel)
> and the ready-made [collector configuration](config/examples/collector.yaml).

Send an OTLP endpoint a real trace in ten minutes: [Getting started](docs/en/getting-started.md).
Before production, read [Configuration](docs/en/configuration.md) — sampling, the flush budget,
request-pipeline metrics and worker identity are the four decisions worth making deliberately.

## Built-in instrumentation

| Component | Spans | Metrics |
|---|---|---|
| HTTP server | `{method} {route}`, server kind, route template, status | `http.server.request.duration`, request/response body size |
| HTTP client | `{method}`, context propagated to the callee | `http.client.request.duration`, request/response body size |
| Doctrine DBAL | statement spans with a query summary, transaction boundaries | `db.client.operation.duration` |
| Messenger | dispatch, send and process spans; context travels on the message | sent, consumed, `messaging.process.duration` |
| Console | one span per command, exit code and errors | `console.command.duration`, off by default |
| Cache | one span per pool operation | `cache.operation.duration`, `cache.lookup.count` with hit/miss |
| Serializer | serialize/deserialize spans inside an existing trace | `serializer.operation.duration`, off by default |
| Mailer | one span per transport send | `mailer.send.duration`, off by default |
| Scheduler | scheduled task spans inside message processing | `scheduler.task.duration`, off by default |
| Monolog | `trace_id`/`span_id` on every record, optional OTLP log export | — |
| PHP runtime | — | `php.memory.usage`, `php.worker.uptime` per worker |

Telemetry follows semantic conventions `1.44.0`. Per-component keys, defaults and the reasoning
behind each: [Instrumentation](docs/en/instrumentation.md).

## Tracing your own code

`Telemetry` is autowired.

```php
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    public function __construct(private Telemetry $telemetry) {}

    public function place(string $orderId, \Closure $work): mixed
    {
        return $this->telemetry->trace('order.place', function (Span $span) use ($orderId, $work): mixed {
            $span->attribute('app.order.id', $orderId);

            return $work();
        });
    }
}
```

The callback runs once. The span closes on the way out, exceptions included, and a telemetry
failure never becomes your application's failure.

To trace and measure the same operation, describe it once. Create the instrument once —
instruments are keyed by name — and hand it to every operation it measures:

```php
$this->duration = $telemetry->metrics()->duration(
    'app.payment.duration',
    unit: DurationUnit::Seconds,
    boundaries: [0.01, 0.05, 0.1, 0.5, 1, 5],
);

$accepted = $telemetry
    ->operation('payment.charge')
    ->duration($this->duration, attributes: ['app.payment.method' => 'card'])
    ->run(function (OperationContext $context) use ($charge): bool {
        $accepted = $charge();

        if (!$accepted) {
            $context->fail('payment.declined');
        }

        return $accepted;
    });
```

The operation owns the clock, so the measurement is correlated with its own span and cannot be
started at the wrong moment. `fail()` marks the span and the histogram at once, without
inventing an exception. Metric attributes stay separate from span attributes on purpose: an
order id belongs in a trace, and in a metric label it is a new time series.

Also in the API: every OpenTelemetry instrument through `metrics()`, `Operation::baggage()` for
values that must travel to the services you call, `Span::traceId()` for putting a trace id on an
error page, and `ActiveTrace` for the code that cannot be handed a span at all — a Monolog
processor, a Doctrine middleware — which reads the running trace as three values rather than as
a handle. See [Application API](docs/en/getting-started.md#the-application-api).

## Worker lifecycle

Weaver distinguishes the PHP process, the Symfony container, one unit of work and one
operation — and only conflates them where the runtime really does:

```text
FPM / request per process          Shared worker (FrankenPHP, RoadRunner, Messenger)
───────────────────────────        ────────────────────────────────────────────────
request                            worker starts
  └─ work                            ├─ request A ─── flush
  └─ terminate ─── shutdown          ├─ request B ─── flush
                                     └─ worker stops ─ shutdown
```

A flush drains a pipeline that keeps living; a shutdown ends it. Which one a boundary performs
follows Symfony's runtime mode, not `PHP_SAPI`. Everything Weaver owns for that unit of work is
released at the boundary either way. See [Architecture and lifecycle](docs/en/architecture.md).

## Testing

`InMemoryTelemetry` is the public test double — no container and no collector:

```php
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;

$telemetry = InMemoryTelemetry::create();

try {
    (new OrderWorkflow($telemetry))->place('order-42', static fn(): bool => true);

    self::assertSame('order.place', $telemetry->spans()[0]->getName());
    self::assertNull($telemetry->activeTrace());  // the operation left nothing active
} finally {
    $telemetry->shutdown();
}
```

## Documentation

- [Getting started](docs/en/getting-started.md) — first trace, first metric, the application API
- [Configuration](docs/en/configuration.md) — environment, budget, sampling, FPM, worker identity
- [Instrumentation](docs/en/instrumentation.md) — per component: what it emits and what it costs
- [Architecture and lifecycle](docs/en/architecture.md) — ownership, boundaries, layers
- [Export resilience](docs/en/export-resilience.md) — the flush budget and what it protects
- [SDK customization](docs/en/sdk-customization.md) — replacing parts of the pipeline
- [`config/minimal_config.yaml`](config/minimal_config.yaml) · [`config/example_config.yaml`](config/example_config.yaml) — every key with its default

Licensed under **MIT**.
