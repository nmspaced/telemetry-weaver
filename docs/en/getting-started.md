# Getting started

By the end of this page a Symfony application sends real traces and metrics to a collector you
can watch, and your own code produces a span and a histogram of its own.

You need PHP 8.4, a Symfony 8.1 application and Docker for the collector.

## 1. Run a collector to look at

Telemetry has to go somewhere. For a first run, a collector that prints what it receives is more
useful than a real backend.

```yaml
# otel-collector.yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318

exporters:
  debug:
    verbosity: detailed

service:
  pipelines:
    traces:
      receivers: [otlp]
      exporters: [debug]
    metrics:
      receivers: [otlp]
      exporters: [debug]
```

```bash
docker run --rm -p 4318:4318 \
    -v "$PWD/otel-collector.yaml:/etc/otelcol-contrib/config.yaml" \
    otel/opentelemetry-collector-contrib:latest
```

Leave it running in its own terminal. Everything the application exports will appear there.

## 2. Install the bundle

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

The last two satisfy `psr/http-client-implementation` and `psr/http-factory-implementation`,
which the OTLP/HTTP exporter needs. If your application already ships a PSR-18 client and a
PSR-17 factory, it has them.

```php
// config/bundles.php
return [
    // ...
    Nmspaced\TelemetryWeaver\TelemetryWeaverBundle::class => ['all' => true],
];
```

## 3. Point the SDK at the collector

Configuration is split in two, and the split is worth knowing from the start:

| | Owns | Examples |
|---|---|---|
| `OTEL_*` environment | the SDK and export | endpoint, protocol, headers, sampler, queue sizes, intervals |
| `open_telemetry.yaml` | what is instrumented and what is recorded | which components, which attributes, the flush budget |

No bundle key duplicates an `OTEL_*` variable.

```dotenv
# .env.local
OTEL_SERVICE_NAME=orders-api
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318

# Traces and metrics on, logs left to your existing pipeline.
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none

# Locally, trace everything. Production is a different number — see Configuration.
OTEL_TRACES_SAMPLER=always_on

# The bundle builds the providers. Leave the SDK autoloader off, or there will be two
# pipelines exporting the same telemetry.
OTEL_PHP_AUTOLOAD_ENABLED=false
```

The bundle itself needs nothing beyond being enabled:

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    enabled: true
```

Defaults instrument every installed component, record nothing that identifies a person, and
give the whole export one second per boundary.

## 4. See the first trace

Make one request to the application — any route.

```bash
curl -s http://localhost:8000/ > /dev/null
```

The collector terminal prints a span named after the route, for example `GET /orders/{id}`,
with `http.request.method`, `http.route` and `http.response.status_code`, plus an
`http.server.request.duration` histogram. Any database query, HTTP call or cache lookup the
request made is a child span underneath it.

If nothing arrives:

- **the collector shows no connection at all** — the endpoint is wrong, or the application
  cannot reach it from inside its container. From a containerised PHP, `127.0.0.1` is that
  container, not the host.
- **the application logs an export failure** — the bundle reports its own failures on the
  `open_telemetry` Monolog channel, together with the SDK's. That channel is where the reason
  will be.
- **nothing anywhere** — check that `OTEL_PHP_AUTOLOAD_ENABLED=false` did not disable more than
  intended and that `open_telemetry.enabled` is not `false` in the current environment. The
  shipped `example_config.yaml` turns the bundle off under `when@test`.

## 5. Trace your own operation

Framework instrumentation shows what Symfony did. Your own spans show what the application did.
`Telemetry` is autowired:

```php
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    public function __construct(private Telemetry $telemetry) {}

    public function place(string $orderId): Order
    {
        return $this->telemetry->trace('order.place', function (Span $span) use ($orderId): Order {
            $span->attribute('app.order.id', $orderId);

            return $this->doPlace($orderId);
        });
    }
}
```

Call it from a controller and the next trace has `order.place` under the server span, with the
queries it ran under it in turn.

The callback runs exactly once, and its return value is yours. The span is closed on the way
out — including when the callback throws, in which case the exception is recorded on the span
and rethrown unchanged.

## 6. Measure it too

A span tells you about one request. A histogram tells you about all of them.

```php
use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    private Duration $duration;

    public function __construct(private Telemetry $telemetry)
    {
        // Once per service: instruments are keyed by name, so asking twice is wasted work.
        $this->duration = $telemetry->metrics()->duration(
            'app.order.place.duration',
            unit: DurationUnit::Seconds,
            boundaries: [0.01, 0.05, 0.1, 0.5, 1, 5],
        );
    }

    public function place(string $orderId): Order
    {
        return $this->telemetry
            ->operation('order.place')
            ->attributes(['app.order.id' => $orderId])
            ->duration($this->duration, attributes: ['app.order.channel' => 'web'])
            ->run(function (OperationContext $context) use ($orderId): Order {
                $order = $this->doPlace($orderId);

                if ($order->isOnHold()) {
                    $context->fail('order.on_hold');
                }

                return $order;
            });
    }
}
```

Two attribute sets, deliberately. `attributes()` describes the span: the order id belongs
there. `duration(..., attributes: ...)` describes the measurement, and every distinct value
there is a new time series — a channel has five values, an order id has millions.

`fail()` marks an unsuccessful outcome on both signals at once, without manufacturing an
exception. An exception that escapes later is still recorded and does not overwrite the reason
you gave.

## The application API

Everything an application needs is in `Nmspaced\TelemetryWeaver\Api`, and nothing in it is an
OpenTelemetry type except the metric instruments themselves.

### `Telemetry`

| Method | Purpose |
|---|---|
| `trace(string $name, \Closure $work, array $attributes = []): mixed` | one span around one callback, the short form |
| `operation(string $name): Operation` | a description to build up before running it |
| `metrics(): Metrics` | instruments |

### `Operation`

Immutable: each call returns a new description, and nothing starts until `run()` or `start()`.

| Method | Purpose |
|---|---|
| `attributes(array $attributes)` | span attributes |
| `kind(SpanKind $kind)` | `Internal`, `Server`, `Client`, `Producer`, `Consumer` |
| `duration(Duration $duration, array $attributes = [])` | measure this operation on that instrument |
| `baggage(array $entries)` | values carried to every service this operation calls |
| `run(\Closure $work): mixed` | run it; returns what the callback returned |
| `start(): RunningOperation` | for work that cannot be expressed as one callback |

`start()` hands back an operation you must `finish()` or `abandon()` yourself, in the execution
context that started it. Prefer `run()`: it cannot be forgotten.

### `OperationContext`, inside the callback

| Method | Purpose |
|---|---|
| `span(): Span` | enrich the span |
| `fail(string $type)` | mark failure on span and measurement |
| `metricAttributes(array $attributes)` | attributes known only once the work has run |
| `baggage(): array` | what the caller propagated, plus what this operation added |

### `Span`

`attribute()`, `attributes()`, `rename()`, `event()`, `recordException()`, `fail()`,
`isRecording()` — and `traceId()` / `spanId()`, which return the lowercase hex of the W3C trace
context, or `null` when nothing is being traced. They exist for showing a trace id on an error
page and for handing it to a system that correlates by id. There is deliberately no way to ask
the facade what trace is running right now: an operation knows its own.

### `Metrics`

`duration()` returns the bundle's own `Duration` handle for use with `Operation::duration()`.
Every other method returns the native OpenTelemetry instrument: `counter()`, `upDownCounter()`,
`histogram()`, `gauge()`, `observableCounter()`, `observableGauge()`,
`observableUpDownCounter()`.

Observable instruments are read at export time, on whatever execution happens to cross a flush
boundary. Their callback must be cheap and must not touch anything that only exists during a
request. Keep the returned handle for as long as the measurements should be reported; releasing
it detaches the callback.

### Baggage

```php
$telemetry
    ->operation('checkout')
    ->baggage(['tenant.id' => $tenantId])
    ->run(function (OperationContext $context): void {
        // Every outgoing call made in here carries tenant.id, and a consumer on the other
        // side reads it back with $context->baggage().
    });
```

Baggage is not an attribute. An attribute describes the span it is set on and stops there;
baggage is added to the outgoing headers of every request the operation makes, so it leaves the
process and reaches services that may not be yours. Put a tenant or a feature cohort in it,
never a token or a personal identifier — there is no way to unsend it. Values read back from
`baggage()` arrived from another service: treat them as input.

### A scope of your own

Telemetry from the application arrives under the instrumentation scope `app`. A library shipping
its own telemetry can name its own:

```php
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;

$telemetry = $factory->scope('acme/billing', version: '2.1.0');
```

## 7. Test it without a collector

`InMemoryTelemetry` implements the same API and records everything in memory:

```php
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;

$telemetry = InMemoryTelemetry::create();

try {
    $order = (new OrderWorkflow($telemetry))->place('order-42');

    self::assertSame('order.place', $telemetry->spans()[0]->getName());
    self::assertNull($telemetry->activeTrace());
} finally {
    $telemetry->shutdown();
}
```

`activeTrace()` is the assertion an ownership bug fails: after a unit of work, nothing may still
be active. It exists on the test double only — the public API offers no way to read ambient
state, which is exactly what is being tested.

`measurements()` returns the delta measurements recorded since the last read, for asserting on
metrics. `reset()` clears both between cases.

## Next

- [Configuration](configuration.md) — what to change before production: sampling, the flush
  budget, a local collector, request-pipeline metrics (FPM, `FRANKENPHP_RESET_KERNEL`), worker identity.
- [Instrumentation](instrumentation.md) — what each component emits, and which captures are
  off until you ask for them.
- [Architecture and lifecycle](architecture.md) — why a worker needs any of this.
