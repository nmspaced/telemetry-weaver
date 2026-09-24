# Getting started

By the end of this page your Symfony application sends real traces and metrics to a collector
you can watch, and your own code produces a span and a histogram of its own.

You need PHP 8.4, a Symfony 8.1 application and Docker for the collector.

Follow the seven steps in order. The [application API](#the-application-api) at the end is
reference material — read it once you have a trace, not before.

## 1. Run a collector to look at

Start with a collector that prints what it receives. It shows you exactly what the application
sent, which a real backend does not.

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

Leave it running in its own terminal. Everything the application exports appears there.

## 2. Install the bundle

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

The last two packages satisfy `psr/http-client-implementation` and
`psr/http-factory-implementation`, which the OTLP/HTTP exporter needs. Skip them if your
application already ships a PSR-18 client and a PSR-17 factory.

Register the bundle:

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

# Locally, trace everything. Production needs a different number — see Configuration.
OTEL_TRACES_SAMPLER=always_on

# The bundle builds the providers. Leave the SDK autoloader off, or two pipelines will
# export the same telemetry.
OTEL_PHP_AUTOLOAD_ENABLED=false
```

Enable the bundle. It needs nothing else:

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    enabled: true
```

The defaults instrument every installed component, record nothing that identifies a person, and
give the whole export one second per boundary.

## 4. See the first trace

Make one request to the application, on any route:

```bash
curl -s http://localhost:8000/ > /dev/null
```

The collector terminal prints a span named after the route — `GET /orders/{id}`, for example —
carrying `http.request.method`, `http.route` and `http.response.status_code`, alongside an
`http.server.request.duration` histogram. Every database query, HTTP call and cache lookup the
request made appears as a child span underneath it.

If nothing arrives, work through these in order:

- **The collector shows no connection at all.** The endpoint is wrong, or the application cannot
  reach it. From inside a container, `127.0.0.1` is that container rather than the host.
- **The application logs an export failure.** Read the `open_telemetry` Monolog channel. The
  bundle reports its own failures there, together with the SDK's.
- **Nothing anywhere.** Check that `OTEL_PHP_AUTOLOAD_ENABLED=false` did not disable more than
  you intended, and that `open_telemetry.enabled` is not `false` in the current environment. The
  shipped `example_config.yaml` turns the bundle off under `when@test`.

## 5. Trace your own operation

Framework instrumentation shows what Symfony did; your own spans show what the application did.
Inject `Telemetry`, which is autowired:

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

Call it from a controller. The next trace shows `order.place` under the server span, with the
queries it ran nested under it in turn.

The callback runs exactly once and its return value is yours. The span closes on the way out,
including when the callback throws: the exception is recorded on the span and rethrown unchanged.

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

The two attribute sets are separate on purpose. `attributes()` describes the span, and the order
id belongs there. `duration(..., attributes: ...)` describes the measurement, where every
distinct value is a new time series: a channel has five values, an order id has millions.

`fail()` marks an unsuccessful outcome on both signals at once, without manufacturing an
exception. An exception that escapes later is still recorded and does not overwrite the reason
you gave.

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

`activeTrace()` reads through the same port as `ActiveTrace::current()`. After a top-level
operation finishes it returns `null`; a nested operation restores its active parent. Assert on
it whenever you want to prove that a unit of work left nothing behind.

`measurements()` returns the delta measurements recorded since the last read, for asserting on
metrics. `reset()` clears spans and measurements between cases.

## The application API

Everything an application needs is in `Nmspaced\TelemetryWeaver\Api`. Nothing in it is an
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
context that started it. Prefer `run()`, which cannot be forgotten.

### `OperationContext`, inside the callback

| Method | Purpose |
|---|---|
| `span(): Span` | enrich the span |
| `fail(string $type)` | mark failure on span and measurement |
| `metricAttributes(array $attributes)` | attributes known only once the work has run |
| `baggage(): array` | what the caller propagated, plus what this operation added |

### `Span`

`attribute()`, `attributes()`, `rename()`, `event()`, `recordException()`, `fail()` and
`isRecording()` enrich the span you were handed.

`traceId()` and `spanId()` return the lowercase hex of the W3C trace context, or `null` when
nothing is being traced. Use them to show a trace id on an error page, or to hand one to a system
that correlates by id.

The facade offers no way to ask what trace is running right now. An operation knows its own, and
code that has no operation reads `ActiveTrace` instead.

### `ActiveTrace`

Some code cannot be handed a `Span` at all. A Monolog processor is given a record and must answer
immediately. A Doctrine driver middleware is called by DBAL from inside the statement it
instruments. Neither has an operation in scope, and neither can be given one, so both read the
trace that is running:

```php
use Nmspaced\TelemetryWeaver\Api\ActiveTrace;

public function __construct(private ActiveTrace $trace) {}

// ...
$current = $this->trace->current();

if ($current !== null && $current->sampled()) {
    $sql = \sprintf("/*traceparent='%s'*/ ", $current->traceparent()) . $sql;
}
```

`current()` returns a `TraceContext` snapshot, or `null` when no valid span is active, when
reading fails, or when the bundle is disabled. Unsampled and remote contexts are still readable.
Turning tracing off or suppressing an operation does not hide an active parent, including a span
opened by another instrumentation library.

| On `TraceContext` | |
|---|---|
| `traceId`, `spanId` | lowercase hex, as the W3C trace context spells them |
| `traceFlags` | the flags byte, not the two characters it is written as |
| `sampled()` | whether bit 0 is set; this does not guarantee export or delivery |
| `traceFlagsHex()` | all flags as two lowercase hex digits, for logs |
| `traceparent()` | W3C version 00; preserves sampled and random, clears reserved bits |

The constructor validates both ids (32 and 16 lowercase hex digits, not all zero) and the flags
byte (0 to 255), throwing `InvalidArgumentException` otherwise.

A `TraceContext` carries three values and no handle. Nothing read this way can end a span another
layer owns or keep a context activation alive past its boundary, which is what makes an ambient
read safe to offer in a worker.

`traceparent()` is formatted by the package rather than asked of a propagator. A propagator writes
whatever `OTEL_PROPAGATORS` selected, which may be B3 and may not include `traceparent` at all.
The callers this exists for need the W3C field specifically, and an unrelated setting must not be
able to empty it. Across a **process** boundary, propagate headers instead: the HTTP client and
Messenger already do, and they honour `OTEL_PROPAGATORS` as they should.

`current()` cannot tell you which span it found. What is current is the innermost enclosing span,
and that differs by where the call sits: inside a Doctrine middleware it is the statement span
only when that middleware runs inside the bundle's own, and inside a Messenger handler it is the
process span rather than the dispatch one. Being inside the span you meant is the caller's job.

### `Metrics`

`duration()` returns the bundle's own `Duration` handle, for use with `Operation::duration()`.
Every other method returns the native OpenTelemetry instrument: `counter()`, `upDownCounter()`,
`histogram()`, `gauge()`, `observableCounter()`, `observableGauge()` and
`observableUpDownCounter()`.

Observable instruments are read at export time, on whatever execution happens to cross a flush
boundary. Keep their callbacks cheap, and keep them away from anything that only exists during a
request. Hold the returned handle for as long as the measurements should be reported; releasing
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

Baggage is not an attribute. An attribute describes the span it is set on and stops there.
Baggage is added to the outgoing headers of every request the operation makes, so it leaves the
process and reaches services that may not be yours. Put a tenant or a feature cohort in it, never
a token or a personal identifier, because there is no way to unsend it. Values read back from
`baggage()` arrived from another service: treat them as input.

### A scope of your own

Telemetry from the application arrives under the instrumentation scope `app`. A library shipping
its own telemetry can name its own:

```php
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;

$telemetry = $factory->scope('acme/billing', version: '2.1.0');
```

## Next

- [Configuration](configuration.md) — what to change before production: sampling, the flush
  budget, a local collector, request-pipeline metrics (FPM, `FRANKENPHP_RESET_KERNEL`), worker identity.
- [Instrumentation](instrumentation.md) — what each component emits, and which captures are
  off until you ask for them.
- [Architecture and lifecycle](architecture.md) — why a worker needs any of this.
