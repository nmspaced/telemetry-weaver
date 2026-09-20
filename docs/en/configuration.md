# Configuration

This page covers the decisions worth making deliberately before production. For every key the
bundle understands, with its default, see
[`config/example_config.yaml`](../../config/example_config.yaml).

## Two layers, no overlap

| | Owns | Set in |
|---|---|---|
| OpenTelemetry SDK and export | endpoint, protocol, headers, sampler, exporters, queue sizes, intervals, resource detectors | `OTEL_*` / `OTEL_PHP_*` environment variables |
| The bundle | what is instrumented, what is recorded, the boundary flush budget | `config/packages/open_telemetry.yaml` |

No bundle key duplicates an `OTEL_*` variable. Where the bundle does take a service id — a
sampler, an exporter, a provider — it is not duplication: a variable picks among the
implementations the SDK already knows, an id hands the pipeline one it does not.

## 1. Export to a local collector

The recommended shape is the OpenTelemetry Collector [agent pattern]: application → collector on
the same host, sidecar or local network → remote backend.

```text
PHP worker
   │ short, bounded OTLP handoff
   ▼
Collector / Alloy, local
   │ queues, retries, credentials, routing
   ▼
remote backend(s)
```

What it buys:

- the application's OTLP latency is small and predictable;
- remote retries and queues happen outside PHP, where they do not block a worker;
- backend credentials live in one place instead of every application;
- a backend outage is the collector's problem, not the request's.

Exporting straight to a remote SaaS endpoint works, but raise timeouts only deliberately.
Telemetry Weaver can bound a standard transport; it cannot shorten a network round trip, and it
is not a durable queue — telemetry that does not fit in the budget is dropped, on purpose.

[agent pattern]: https://opentelemetry.io/docs/collector/deploy/agent/

## 2. Protocol

**HTTP/protobuf** is the default and the simplest:

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

It needs `open-telemetry/exporter-otlp` plus a PSR-18 client and PSR-17 factory.

**gRPC** when the environment already speaks it:

```bash
composer require open-telemetry/transport-grpc   # requires ext-grpc
```

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4317
```

The budget applies the same way to both. Test the gRPC path in the real deployment image,
especially TLS and recovery after the collector restarts.

## 3. Two timeouts, and which one is which

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
deadline for one execution boundary — the end of a request, a finished command, a worker between
messages — covering every signal it has to send. Each send gets the smaller of the two.

With more than one collector, the boundary deadline is shared among them rather than multiplied
by them. See [Export resilience](export-resilience.md).

A local collector is comfortable with 250–500 ms per send and 500–1000 ms per boundary. Then
measure. A remote collector that needs whole seconds is better moved closer to the application
than given a larger share of every request.

## 4. Keep retries out of PHP

```yaml
open_telemetry:
    sdk:
        export:
            max_retries: 0    # the default
```

The standard PHP transport retries synchronously: every attempt sleeps in the calling process.
With a collector that is down, three retries turn one failed export into several timeouts plus
backoff, inside a worker that should be serving traffic. Losing that telemetry is the cheaper
outcome.

Configure retries and a sending queue on the collector, where they are asynchronous.

## 5. Traces: sampler, processor, queue

```dotenv
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1

OTEL_PHP_TRACES_PROCESSOR=batch
OTEL_BSP_SCHEDULE_DELAY=1000
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512
```

Sampling is the first lever, not the last. Decide it before shrinking queues to compensate for
volume. `parentbased_traceidratio` keeps an incoming decision and samples new root traces at the
given rate; `always_on` is right for local development.

The bundle builds the batch processor with auto-flush disabled: `span->end()` only enqueues, and
the queue is drained at execution boundaries. If the queue fills before the next boundary, spans
are dropped rather than exported inline — bounded loss instead of an unbounded queue or a
network wait inside business code. Size the queue for the volume **per worker**.

`OTEL_PHP_TRACES_PROCESSOR=simple` opts out of all of that: it exports when a span ends, putting
the collector's latency back on the request path.

When the sampling decision cannot be written as one of the SDK's samplers — never this health
check, always checkout, five percent of the rest — configure a service instead and keep
everything the bundle builds around it:

```yaml
open_telemetry:
    sdk:
        traces:
            sampler: App\Telemetry\PerRouteSampler
```

See [SDK customization](sdk-customization.md).

## 6. Metrics: cadence and temporality

```dotenv
OTEL_METRIC_EXPORT_INTERVAL=15000
```

Metrics are exported at execution boundaries. Every export is a blocking send, so the interval is
what keeps a busy application from exporting on every single boundary. Leaving
`metrics.flush_interval_ms: null` follows `OTEL_METRIC_EXPORT_INTERVAL`, which is what the
exporter would have run on anyway; set it only to flush *less* often than that.

Temporality follows the SDK preference, but per instrument rather than one setting for all:

```dotenv
OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta
```

Under `delta`, counters and histograms are delta; up-down counters and gauge-like state stay
cumulative, because the current value is the point of those and a consumer should not have to
replay every delta since process start to learn how much memory a worker is using.

If the backend does not need delta, leave the SDK default.

## 7. Logs: correlation and export are separate

Correlation adds `trace_id` and `span_id` to log records so a line can be found from a trace and
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

Keeping it off is the right answer when logs already reach Loki, Elastic or journald. To turn it
on you need `symfony/monolog-bundle` — the handler is inserted into Monolog's own stack — and an
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

The `open_telemetry` channel is never exported, whatever this list says: a record about a failed
export, exported, refills the queue the failure came from.

## 8. One endpoint or several

One local collector is the simple case — for OTLP/HTTP the SDK derives `/v1/traces`,
`/v1/metrics` and `/v1/logs` from the base:

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

## 9. Headers and authentication

```dotenv
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20token
```

`sdk.exporter_otlp_headers` merges over that variable for the bundle's OTLP transports: entries
here win on a collision, and a `null` value removes a header the variable set. A custom transport
family owns its own authentication and does not see this merge.

## 10. Worker identity and runtime mode

Whether the pipeline outlives a request is not a bundle setting: it follows Symfony's
`kernel.runtime_mode.web` / `kernel.runtime_mode.worker`, resolved from `APP_RUNTIME_MODE`. Do
not infer it from `PHP_SAPI`.

| Runtime | Mode | At a boundary |
|---|---|---|
| PHP-FPM, one request per PHP execution | `web=1&worker=0` | flush and shut the pipeline down on terminate |
| Shared HTTP worker (FrankenPHP, RoadRunner) | `worker=1` | flush per request, shut down when the process exits |
| Worker that rebuilds its kernel per request | `worker=2` | shut the pipeline down per request, worker identity stays stable |
| Console, Messenger | — | flush per command or message, shut down at process exit |

FrankenPHP integrates with this model. For a custom RoadRunner or bootstrap integration, verify
the resolved parameters; a shared HTTP worker must be visible as `web=1&worker=1`, which can be
set explicitly:

```dotenv
APP_RUNTIME_MODE=web=1&worker=1
```

Long-lived workers get a stable `service.instance.id` from the SDK's detector, which is what
tells two workers of the same service apart. Set one by hand only if you can guarantee it is
unique per concurrent worker — the same hostname on every process makes their series
indistinguishable, which is worse than the generated value.

## 11. FPM request metrics

A request-per-process pipeline starts a new MeterProvider for every request, so cumulative
counters restart constantly and worker state means nothing. Request metrics are therefore off:

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: disabled    # the default
```

Enable only with the downstream model in mind:

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: delta
```

In `delta` mode, counters and histograms are exported with delta temporality chosen before
aggregation; everything else stays cumulative and describes that one request. It requires:

- a resource that tells writers apart — `service.instance.id`, or `process.pid` plus host or
  container attributes. One shared `service.instance.id=${HOSTNAME}` across FPM children is
  exactly the case this breaks on;
- detectors that actually provide that identity. Restricting `OTEL_PHP_DETECTORS` to `env`
  removes it. A safe explicit set is `env,host,process,process_runtime,sdk`; the SDK default
  `all` is also fine;
- a collector pipeline that accepts independent short-lived delta sequences. A stateful
  delta-to-cumulative processor may read each request as a reset.

An explicit cumulative `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` conflicts with this
mode. When the requirements are not met, metrics stay off and a diagnostic says why. Traces and
logs are unaffected either way.

## 12. Development

`open-telemetry/context` wraps every context activation in a debug scope under `assert()`, which
allocates an object per span and reports scopes detached out of order. With `zend.assertions=1` —
the development default — those reports are worth reading: this bundle's whole ownership model is
about scopes that must not outlive the work that opened them.

In production `zend.assertions=-1` compiles the assertion out and the question does not arise. If
a development worker is hot enough for the overhead to matter, turn off just the wrapping:

```dotenv
OTEL_PHP_DEBUG_SCOPES_DISABLED=true
```

Do not set it anywhere you would want to be told about a leaked scope.

The bundle's own diagnostics — an instrumentation that threw, a scope closed out of order, a
failed export — go to the `open_telemetry` Monolog channel, rate-limited, together with the SDK's
own output:

```yaml
open_telemetry:
    diagnostics:
        enabled: true
        detailed_per_process: 10
        min_interval_seconds: 60.0
```

`diagnostics.enabled: false` silences the SDK too, rather than returning it to `error_log()`.

## Complete examples

- [`config/examples/worker.env`](../../config/examples/worker.env) — shared worker over OTLP/HTTP
- [`config/examples/fpm.env`](../../config/examples/fpm.env) — request per process
- [`config/examples/grpc.env`](../../config/examples/grpc.env) — OTLP/gRPC
- [`config/examples/split-endpoints.env`](../../config/examples/split-endpoints.env) — one backend per signal
- [`config/examples/worker.yaml`](../../config/examples/worker.yaml) · [`config/examples/fpm.yaml`](../../config/examples/fpm.yaml)
- [`config/example_config.yaml`](../../config/example_config.yaml) — every key with its default

OpenTelemetry references: [PHP SDK configuration](https://opentelemetry.io/docs/languages/php/sdk/) ·
[OTLP exporter configuration](https://opentelemetry.io/docs/languages/sdk-configuration/otlp-exporter/)
