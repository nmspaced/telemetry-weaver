# Configuration

This page covers the decisions worth making deliberately before production. Each section solves
one problem; read the ones you need, in any order. For every key the bundle understands, with its
default, see [`config/example_config.yaml`](../../config/example_config.yaml).

## Two layers, no overlap

| | Owns | Set in |
|---|---|---|
| OpenTelemetry SDK and export | endpoint, protocol, headers, sampler, exporters, queue sizes, intervals, resource detectors | `OTEL_*` / `OTEL_PHP_*` environment variables |
| The bundle | what is instrumented, what is recorded, the boundary flush budget | `config/packages/open_telemetry.yaml` |

No bundle key duplicates an `OTEL_*` variable. Where the bundle takes a service id for a sampler,
an exporter or a provider, that is not duplication: a variable picks among the implementations the
SDK already knows, while an id hands the pipeline one it does not.

## Export to a local collector

Send telemetry to a collector on the same host, as a sidecar or over the local network, and let
that collector talk to the remote backend.

```text
PHP worker
   │ short, bounded OTLP handoff
   ▼
Collector / Alloy, local
   │ queues, retries, credentials, routing
   ▼
remote backend(s)
```

This is the [agent pattern], and it buys four things:

- the application's OTLP latency stays small and predictable;
- remote retries and queues happen outside PHP, where they do not block a worker;
- backend credentials live in one place instead of in every application;
- a backend outage becomes the collector's problem rather than the request's.

Exporting straight to a remote SaaS endpoint works, but raise the timeouts only deliberately.
Telemetry Weaver bounds a standard transport; it cannot shorten a network round trip, and it is
not a durable queue. Telemetry that does not fit in the budget is dropped, on purpose.

[agent pattern]: https://opentelemetry.io/docs/collector/deploy/agent/

## Choose a protocol

Use **HTTP/protobuf** unless you have a reason not to. It is the default and the simplest:

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

It needs `open-telemetry/exporter-otlp` plus a PSR-18 client and a PSR-17 factory.

Use **gRPC** when the environment already speaks it:

```bash
composer require open-telemetry/transport-grpc   # requires ext-grpc
```

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4317
```

The budget applies the same way to both. Test the gRPC path in the real deployment image,
especially TLS and recovery after the collector restarts.

## Set the two timeouts

```dotenv
OTEL_EXPORTER_OTLP_TIMEOUT=500
```

```yaml
open_telemetry:
    sdk:
        export:
            flush_timeout_ms: 1000
```

`OTEL_EXPORTER_OTLP_TIMEOUT` is the ceiling for one OTLP send. `flush_timeout_ms` is the total
deadline for one execution boundary, covering every signal that boundary has to send. A boundary
is the end of a request, a finished command, or a worker between messages. Each send gets the
smaller of the two values.

With more than one collector, the boundary deadline is shared among them rather than multiplied
by them. See [Export resilience](export-resilience.md).

Start a local collector at 250–500 ms per send and 500–1000 ms per boundary, then measure. A
remote collector that needs whole seconds is better moved closer to the application than given a
larger share of every request.

## Keep retries out of PHP

```yaml
open_telemetry:
    sdk:
        export:
            max_retries: 0    # the default
```

The standard PHP transport retries synchronously, so every attempt sleeps in the calling process.
When a collector is down, three retries turn one failed export into several timeouts plus
backoff, inside a worker that should be serving traffic. Losing that telemetry is the cheaper
outcome.

Configure retries and a sending queue on the collector instead, where they are asynchronous.

## Sample and queue traces

```dotenv
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1

OTEL_PHP_TRACES_PROCESSOR=batch
OTEL_BSP_SCHEDULE_DELAY=1000
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512
```

Decide sampling before you shrink queues to compensate for volume.
`parentbased_traceidratio` keeps an incoming decision and samples new root traces at the given
rate. Use `always_on` for local development.

The bundle builds the batch processor with auto-flush disabled. `span->end()` only enqueues, and
the queue drains at execution boundaries. If the queue fills before the next boundary, spans are
dropped rather than exported inline: bounded loss instead of an unbounded queue or a network wait
inside business code. Size the queue for the volume **per worker**.

`OTEL_PHP_TRACES_PROCESSOR=simple` opts out of all of that and exports when a span ends, which
puts the collector's latency back on the request path.

When the sampling decision cannot be written as one of the SDK's samplers — never this health
check, always checkout, five percent of the rest — configure a service instead, and keep
everything the bundle builds around it:

```yaml
open_telemetry:
    sdk:
        traces:
            sampler: App\Telemetry\PerRouteSampler
```

See [SDK customization](sdk-customization.md).

## Set the metric cadence and temporality

```dotenv
OTEL_METRIC_EXPORT_INTERVAL=15000
```

Metrics are exported at execution boundaries, and every export is a blocking send. The interval
is what keeps a busy application from exporting on every single boundary. Leaving
`metrics.flush_interval_ms: null` follows `OTEL_METRIC_EXPORT_INTERVAL`, which the exporter would
have run on anyway; set it only to flush *less* often than that.

Temporality follows the SDK preference, applied per instrument rather than as one setting for all:

```dotenv
OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta
```

Under `delta`, counters and histograms are delta. Up-down counters and gauge-like state stay
cumulative, because the current value is the point of those: a consumer should not have to replay
every delta since process start to learn how much memory a worker is using.

Leave the SDK default if the backend does not need delta.

## Correlate and export logs

Correlation adds `trace_id` and `span_id` to log records, so a line can be found from a trace and
a trace from a line. It costs nothing downstream and is on by default.

Exporting the records themselves through OTLP is a second destination for every log line, and is
off:

```yaml
open_telemetry:
    logs:
        correlation:
            enabled: true
        export:
            enabled: false
```

Keep export off when logs already reach Loki, Elastic or journald. To turn it on you need
`symfony/monolog-bundle`, because the handler is inserted into Monolog's own stack, and an
exporter to name:

```dotenv
OTEL_LOGS_EXPORTER=otlp
OTEL_BLRP_SCHEDULE_DELAY=1000
OTEL_BLRP_MAX_QUEUE_SIZE=2048
OTEL_BLRP_MAX_EXPORT_BATCH_SIZE=512
```

```yaml
open_telemetry:
    logs:
        export:
            enabled: true
            level: info                  # not debug: shipping every debug line is a bandwidth decision
            excluded_channels: []
```

Log export uses the same queue-and-drain shape as traces, so emitting a log record does not wait
for the collector.

The `open_telemetry` channel is never exported, whatever this list says. A record about a failed
export, exported, refills the queue the failure came from.

## Send each signal to its own endpoint

One local collector is the simple case. For OTLP/HTTP the SDK derives `/v1/traces`, `/v1/metrics`
and `/v1/logs` from the base:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Per-signal endpoints are used exactly as given, so they must include the signal path:

```dotenv
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://mimir:4318/v1/metrics
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://loki:4318/v1/logs
```

Three hosts are three independent failure domains, and the flush budget treats them that way.
Three paths on one host are one.

## Authenticate to the collector

```dotenv
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20token
```

`sdk.exporter_otlp_headers` merges over that variable for the bundle's OTLP transports. Entries
there win on a collision, and a `null` value removes a header the variable set. A custom transport
family owns its own authentication and does not see this merge.

## Declare the runtime mode and worker identity

Whether the pipeline outlives a request is not a bundle setting. It follows Symfony's
`kernel.runtime_mode.web` and `kernel.runtime_mode.worker`, resolved from `APP_RUNTIME_MODE`. Do
not infer it from `PHP_SAPI`.

| Runtime | Mode | At a boundary |
|---|---|---|
| PHP-FPM, one request per PHP execution | `web=1&worker=0` | flush and shut the pipeline down on terminate |
| Shared HTTP worker (FrankenPHP, RoadRunner) | `worker=1` | flush per request, shut down when the process exits |
| Worker that rebuilds its kernel per request | `worker=2` | shut the pipeline down per request, worker identity stays stable |
| Console, Messenger | — | flush per command or message, shut down at process exit |

FrankenPHP integrates with this model. For a custom RoadRunner or bootstrap integration, verify
the resolved parameters: a shared HTTP worker must be visible as `web=1&worker=1`, which you can
set explicitly.

```dotenv
APP_RUNTIME_MODE=web=1&worker=1
```

Long-lived workers get a stable `service.instance.id` from the SDK's detector, which is what tells
two workers of the same service apart. Set one by hand only if you can guarantee it is unique per
concurrent worker. The same hostname on every process makes their series indistinguishable, which
is worse than the generated value.

## Export metrics from a request-per-process runtime (FPM, `FRANKENPHP_RESET_KERNEL`)

PHP-FPM and FrankenPHP with `FRANKENPHP_RESET_KERNEL=1` build a new container, and with it a new
MeterProvider, for every request. A cumulative counter from such a pipeline starts at zero on
every request, so **metrics from these runtimes are off by default**. Traces and logs are not
affected.

You can export them under two conditions: the application sends **delta** temporality, and
something downstream turns delta back into cumulative if the backend needs it.

### 1. Opt in to delta

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: delta    # default: disabled
```

Every request then exports what it recorded, once, after the response is sent:

- Counters and histograms are DELTA streams. Each FPM child, or FrankenPHP worker thread, is one
  stream that continues across the requests it serves.
- UpDownCounters and gauges stay cumulative and describe that one request.
- `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` does not apply here. It still governs
  long-lived processes.

**Writer identity.** A backend has to tell the streams apart. Under FPM the bundle derives a
stable `service.instance.id` for each child: a UUID v5 of `container.id`, `host.id`, `host.name`
and `process.pid`. It is the same on every request the child serves and differs between children.
This matters most on the Prometheus mapping, where `instance` comes from `service.instance.id` and
nothing else; without the id, every child would write into one series. FrankenPHP worker threads
already carry the SDK's per-thread id. An id you set yourself through `OTEL_RESOURCE_ATTRIBUTES` or
`sdk.resource_attributes` always wins, and it must still be unique per child: one
`service.instance.id=${HOSTNAME}` shared by a whole pool is exactly what breaks.

The derivation needs the SDK's `process` detector and at least one of `host` or `container`. The
SDK default `OTEL_PHP_DETECTORS=all` includes them, and `env,host,process,process_runtime,sdk` is a
safe explicit set. Without them no id is derived, and metrics are exported anyway.

The bundle does not check whether the backend accepts delta or whether writers are told apart.
Getting the receiving side right is the deployment's responsibility.

### 2. Convert delta to cumulative in the collector

Prometheus, Mimir, and everything fed by Prometheus remote write store cumulative series. Put the
collector's `deltatocumulative` processor in front of them:

```yaml
processors:
    deltatocumulative:
        max_stale: 5m
        max_streams: 100000

service:
    pipelines:
        metrics:
            receivers: [otlp]
            processors: [memory_limiter, deltatocumulative, batch]
            exporters: [otlphttp/prometheus]
```

A complete, validated configuration is in
[`config/examples/collector.yaml`](../../config/examples/collector.yaml). Three things about it
matter:

- **The processor keeps state per stream, so every point of a stream must reach the same collector
  instance.** Run the collector as an agent: a sidecar, or one per node that the pods on that node
  send to. A pool of gateways needs a `loadbalancing` exporter with `routing_key: streamID` in
  front of it. Round-robin across stateful collectors silently produces wrong totals.
- `max_stale` bounds how long a quiet stream is remembered. A child recycled by `pm.max_requests`
  stops writing, and its series ends after that interval. `max_streams` bounds memory, roughly
  children per host × hosts × series per child. Streams above it are dropped.
- A backend that ingests delta natively needs no processor. Send the data as is.

### 3. What it costs

- **One OTLP metrics export per request**, after the response is sent. It is inside the boundary
  budget (`sdk.export.flush_timeout_ms`), but the collector sees one request per PHP request.
- **One series per FPM child**, plus new series whenever children are recycled. Aggregate in
  queries, for example `sum without (instance) (rate(http_server_request_duration_seconds_count[5m]))`.
  A high or unlimited `pm.max_requests` keeps the churn down.

Worker metrics (`php.memory.usage`, `php.worker.uptime`) are never recorded in these runtimes,
because a pipeline that lives for one request has no worker state to report.

## Keep debug scopes on in development

`open-telemetry/context` wraps every context activation in a debug scope under `assert()`, which
allocates an object per span and reports scopes detached out of order. With `zend.assertions=1`,
the development default, those reports are worth reading: this bundle's whole ownership model is
about scopes that must not outlive the work that opened them.

In production `zend.assertions=-1` compiles the assertion out and the question does not arise. If
a development worker is hot enough for the overhead to matter, turn off just the wrapping:

```dotenv
OTEL_PHP_DEBUG_SCOPES_DISABLED=true
```

Do not set it anywhere you would want to be told about a leaked scope.

## Read the bundle's own diagnostics

An instrumentation that threw, a scope closed out of order, a failed export — all of it goes to
the `open_telemetry` Monolog channel, rate-limited, together with the SDK's own output:

```yaml
open_telemetry:
    diagnostics:
        enabled: true
        detailed_per_process: 10
        min_interval_seconds: 60.0
```

`diagnostics.enabled: false` silences the SDK too, rather than returning it to `error_log()`.

## Complete examples

Recommended sets, each checked end to end against a real collector, Prometheus and Jaeger:

- Workers that keep their kernel (FrankenPHP worker mode, RoadRunner, Messenger):
  [`worker.env`](../../config/examples/worker.env) · [`worker.yaml`](../../config/examples/worker.yaml)
- PHP-FPM, or FrankenPHP with `FRANKENPHP_RESET_KERNEL=1`:
  [`fpm.env`](../../config/examples/fpm.env) · [`fpm.yaml`](../../config/examples/fpm.yaml)
- The collector for both: [`collector.yaml`](../../config/examples/collector.yaml)

Variations:

- [`config/examples/grpc.env`](../../config/examples/grpc.env) — OTLP/gRPC
- [`config/examples/split-endpoints.env`](../../config/examples/split-endpoints.env) — one backend per signal
- [`config/example_config.yaml`](../../config/example_config.yaml) — every key with its default

OpenTelemetry references: [PHP SDK configuration](https://opentelemetry.io/docs/languages/php/sdk/) ·
[OTLP exporter configuration](https://opentelemetry.io/docs/languages/sdk-configuration/otlp-exporter/)
