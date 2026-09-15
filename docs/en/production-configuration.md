# Production configuration

Telemetry Weaver deliberately splits configuration into two layers:

- **`OTEL_*` / `OTEL_PHP_*` environment variables** configure the OpenTelemetry SDK and exporters: endpoint, protocol, sampling, queue sizes, resource detectors, OTLP headers and compression.
- **`open_telemetry.yaml`** configures Symfony instrumentation and Weaver-specific lifecycle/resilience behavior.

Avoid duplicating SDK settings in Symfony config when the standard OpenTelemetry variable already exists.

## 1. Prefer a local Collector / Alloy

The recommended production shape is the OpenTelemetry Collector **agent pattern**: application SDK → Collector on the same host/sidecar/local network → remote backend.

Benefits:

- application-facing OTLP latency is predictable and small;
- remote retries/queues do not block PHP;
- backend credentials can live in the Collector instead of every application;
- one local endpoint can fan traces, metrics and logs out to different remote systems;
- backend outages become a Collector concern rather than an application concern.

Direct export to a remote SaaS endpoint is supported, but raise timeouts only deliberately: Weaver can bound standard transports, not eliminate the network round trip.

Official reference: [OpenTelemetry Collector agent deployment pattern](https://opentelemetry.io/docs/collector/deploy/agent/).

## 2. HTTP/protobuf vs gRPC

### HTTP/protobuf

The simplest default:

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Install the normal OTLP exporter plus a PSR HTTP client implementation (the README example uses Symfony HttpClient + Nyholm PSR-7).

### gRPC

Use gRPC when your environment already supports it or you prefer the gRPC transport:

```bash
composer require open-telemetry/transport-grpc
```

`ext-grpc` is required by that package.

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4317
```

Budget semantics are the same: each OTLP send receives the destination's current allowance as its deadline. Test the real gRPC path in your deployment image, especially TLS/mTLS and failure recovery.

## 3. Timeout and boundary budget are different

`OTEL_EXPORTER_OTLP_TIMEOUT` is the maximum timeout of one normal OTLP transport send. `sdk.export.flush_timeout_ms` is Weaver's **total execution-boundary deadline**.

Example:

```dotenv
OTEL_EXPORTER_OTLP_TIMEOUT=500
```

```yaml
open_telemetry:
    sdk:
        export:
            flush_timeout_ms: 1000
```

Inside a boundary, the default transport uses the smaller of its configured timeout and the current destination allowance. With multiple collectors, the global 1000 ms is shared rather than multiplied.

For a local Collector start around 250–500 ms transport timeout and 500–1000 ms total boundary budget, then measure. Remote endpoints often need more, but a remote Collector that needs seconds should usually be moved closer to the application instead of giving PHP a very large wait budget.

## 4. Retry outside PHP

Keep:

```yaml
open_telemetry:
    sdk:
        export:
            max_retries: 0
```

The standard PHP transport retries synchronously. If the Collector is unavailable, retries turn one failed export into extra sleeps/timeouts in the application process.

Configure `sending_queue` / retry on Collector exporters instead.

## 5. Trace processor and queue

Recommended:

```dotenv
OTEL_PHP_TRACES_PROCESSOR=batch
OTEL_BSP_SCHEDULE_DELAY=1000
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512
```

Telemetry Weaver builds the batch processor with **auto-flush disabled**. `span->end()` only enqueues; the queue is drained at controlled boundaries using the schedule delay as the cadence.

If the queue fills before a boundary, spans may be dropped. This is intentional: a bounded loss is safer than an unbounded queue or an export wait inside business code.

Setting `OTEL_PHP_TRACES_PROCESSOR=simple` is a conscious opt-out from this behavior: a simple processor exports on span end and puts network latency back on the application path.

## 6. Metrics

For long-lived workers, metrics are exported on execution boundaries; `OTEL_METRIC_EXPORT_INTERVAL` is used as the default minimum cadence when bundle `metrics.flush_interval_ms` is `null`.

```dotenv
OTEL_METRIC_EXPORT_INTERVAL=15000
```

Metric temporality follows the configured preference but remains instrument-aware. You may set:

```dotenv
OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta
```

if your backend/Collector is designed for delta counters/histograms. UpDown/state instruments remain cumulative under Weaver's selector.

If you do not need delta, leave the SDK default instead of setting it just because the option exists.

## 7. FPM request metrics

Request-based pipelines are special: a new MeterProvider starts with every request. Cumulative counters would therefore restart every request and form misleading streams.

Request metrics are disabled by default:

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: disabled
```

Enable only when you understand the downstream model:

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: delta
```

In this mode synchronous counters/histograms are delta per request; state-like instruments stay cumulative and describe that request's short-lived pipeline.

Requirements:

- the Resource must distinguish writers (`service.instance.id`, or `process.pid` plus host/container identity);
- do not assign one shared `service.instance.id=${HOSTNAME}` to many FPM children;
- do not restrict `OTEL_PHP_DETECTORS` to `env` only unless you provide another unique writer identity;
- downstream must understand independent short-lived delta sequences. A stateful delta-to-cumulative processor can treat each request as a reset, so validate your Collector pipeline before enabling this globally.

A safe explicit detector set is, for example:

```dotenv
OTEL_PHP_DETECTORS=env,host,process,process_runtime,sdk
```

The SDK default `all` is also suitable; the main warning is against removing host/process identity accidentally.

## 8. Worker identity and `APP_RUNTIME_MODE`

Long-lived workers get a stable automatic `service.instance.id`. Usually do **not** set one manually unless you can guarantee uniqueness per concurrent worker.

Symfony's runtime mode tells Weaver whether providers outlive a request. FrankenPHP integrates with this model. For a custom RoadRunner/bootstrap integration, verify the resolved Symfony parameters; a shared HTTP worker should effectively be `web=1&worker=1`.

If your runtime adapter does not set it itself, `APP_RUNTIME_MODE=web=1&worker=1` is the shape Weaver expects for a shared-kernel HTTP worker.

## 9. Logs

Trace/span correlation is enabled separately from OTLP log export.

If logs already go through another pipeline, keep:

```dotenv
OTEL_LOGS_EXPORTER=none
```

```yaml
open_telemetry:
    logs:
        export:
            enabled: false
```

To enable OTLP logs:

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
```

The bundle always builds its OTLP log path with a batch processor and disables auto-flush, so log emission itself does not intentionally wait for the Collector.

## 10. One endpoint vs split endpoints

One local Collector is simplest:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

For OTLP/HTTP, the SDK derives `/v1/traces`, `/v1/metrics` and `/v1/logs` from that base.

Per-signal endpoints are used **as-is**, so include the full signal path:

```dotenv
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://mimir:4318/v1/metrics
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://loki:4318/v1/logs
```

With Weaver's default transport, destinations are deduplicated by origin (`scheme://host:port`). Different paths on one Collector share one budget; different origins get independent shares of the same global boundary deadline.

## 11. Headers and authentication

Prefer standard OTLP headers:

```dotenv
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20token
```

`open_telemetry.sdk.exporter_otlp_headers` can override/add headers for the standard Weaver OTLP transport; a `null` value removes a header from the env-derived set.

A custom transport family bypasses this Weaver header merge and owns its own authentication/configuration.

## 12. Sampling

A common production starting point:

```dotenv
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1
```

The correct value depends on traffic, incident/debug requirements and backend cost. Configure sampling **before** trying to compensate for excessive telemetry by shrinking queues.

For local development use `always_on` when full traces are useful.

## Complete examples

- [`config/examples/worker.env`](../../config/examples/worker.env)
- [`config/examples/fpm.env`](../../config/examples/fpm.env)
- [`config/examples/grpc.env`](../../config/examples/grpc.env)
- [`config/examples/split-endpoints.env`](../../config/examples/split-endpoints.env)
- [`config/examples/worker.yaml`](../../config/examples/worker.yaml)
- [`config/examples/fpm.yaml`](../../config/examples/fpm.yaml)
- [full bundle configuration reference](../../config/example_config.yaml)

OpenTelemetry references:

- [PHP SDK configuration](https://opentelemetry.io/docs/languages/php/sdk/)
- [OTLP exporter configuration](https://opentelemetry.io/docs/languages/sdk-configuration/otlp-exporter/)
- [Collector agent deployment pattern](https://opentelemetry.io/docs/collector/deploy/agent/)
