# SDK customization

The defaults are safe, not mandatory. Every part of the export pipeline can be replaced with a
service of your own through `open_telemetry.sdk.*`, which takes service ids.

This is not the same thing as an `OTEL_*` variable, and the distinction is worth keeping
straight: a variable picks among implementations the SDK already knows; a service id hands the
pipeline one it does not.

**Everything here is validated when the container compiles.** A misspelled id, a service that
does not implement the required interface, or a combination that describes a pipeline which
cannot exist is a build error — not a surprise on the first request in production.

## Two kinds of override

Some decisions live *inside* the provider the bundle builds. Others replace a link of the chain
around it. The first kind keeps every guarantee; the second trades some away.

```text
inside the provider                    around the provider
───────────────────                    ───────────────────
sampler                                transport family
id generator                           exporter
span processors                        provider
metric views

keeps: queueing, boundary budget,      gives up: progressively more of it,
export gate, flush lifecycle           see the matrix below
```

Prefer the first kind. It is enough for most of what people reach for a provider override to do.

## Inside the provider

### Sampler

For a decision no `OTEL_TRACES_SAMPLER` value can express — never this health check, always
checkout, five percent of the rest, or anything that depends on the route, tenant or user:

```yaml
open_telemetry:
    sdk:
        traces:
            sampler: app.telemetry.per_route_sampler
```

Implements `OpenTelemetry\SDK\Trace\SamplerInterface`, and is used instead of
`OTEL_TRACES_SAMPLER`.

### Id generator

```yaml
open_telemetry:
    sdk:
        traces:
            id_generator: app.telemetry.xray_id_generator
```

Implements `OpenTelemetry\SDK\Trace\IdGeneratorInterface`. Backends that read structure out of
the trace id need this — AWS X-Ray requires the start timestamp in the id's first four bytes.

### Span processors

```yaml
open_telemetry:
    sdk:
        traces:
            span_processors:
                - app.telemetry.redacting_processor
```

`OpenTelemetry\SDK\Trace\SpanProcessorInterface` services, added **in front of** the bundle's
own — so a processor that edits a span as it ends sees it before it is queued for export. They
are added to the pipeline, never instead of it.

### Metric views

A view changes what an instrument produces without changing the code that writes it: a different
aggregation, a narrower set of attributes, or an instrument dropped entirely.

```yaml
open_telemetry:
    sdk:
        metrics:
            views:
                - app.telemetry.drop_route_label
```

```php
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricView;
use OpenTelemetry\SDK\Metrics\View\SelectionCriteria\InstrumentNameCriteria;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;

$services
    ->set('app.telemetry.drop_route_label', MetricView::class)
    ->args([
        new InstrumentNameCriteria('http.server.request.duration'),
        ViewTemplate::create()->withAttributeKeys(['http.response.status_code']),
    ]);
```

`MetricView` is a pair — the SDK has no type for one — and both halves are the SDK's own types,
passed through untouched.

To change the boundaries of a histogram the bundle created, use
[`instrumentation.<component>.duration_buckets`](instrumentation.md#histogram-boundaries)
instead; it needs no service. Views are for what that cannot reach: an instrument the bundle did
not create, and attribute keys whose cardinality has to be cut at the source.

All four of these are **refused next to a `provider` for the same signal**. A provider builds its
own sampling, id generation, processors and views, so naming them beside one describes a
pipeline that will not exist — and that is a configuration error, not something to ignore
silently.

## Around the provider

### Transport family

```yaml
open_telemetry:
    sdk:
        otlp:
            transport_factories:
                http: app.telemetry.http_transport_factory
                grpc: app.telemetry.grpc_transport_factory
```

The service implements `OpenTelemetry\SDK\Common\Export\TransportFactoryInterface`.

HTTP and gRPC are separate families because a generic factory cannot tell gRPC from
`http/protobuf` by its arguments alone. The HTTP family covers the HTTP OTLP protocols the
installed SDK supports (`http/protobuf`, `http/json`, and `http/ndjson` where available). A
family left unset keeps the bundle's own transport.

A custom family bypasses the bundle's transport layer, and therefore:

- no flush budget for that family — the transport's own timeout applies, and a transport that
  ignores its timeout holds the boundary for as long as it waits;
- no `max_retries` override;
- no `sdk.exporter_otlp_headers` merge — authentication is yours.

The resilient exporter above it still catches failures and respects the export gate. The SDK
still resolves the protocol through its own registry, so it must still know the protocol you
selected.

### Exporter

```yaml
open_telemetry:
    sdk:
        traces:
            exporter: app.telemetry.span_exporter
```

The service implements the SDK exporter interface for that signal, and is used instead of
`OTEL_<SIGNAL>_EXPORTER`. The bundle's provider still drives it, still wraps it in the resilient
exporter and the export gate, and a custom metrics exporter still follows
`runtime.request_metrics`.

What it cannot do is make an arbitrary exporter interruptible. If its `export()` blocks for ten
seconds, `flush_timeout_ms` cannot stop that PHP code.

An exporter and a provider for the same signal is an error.

### Provider

```yaml
open_telemetry:
    sdk:
        traces:
            provider: app.telemetry.tracer_provider
```

This replaces the signal's SDK pipeline completely: processors, exporter, transport, auto-flush
and timeout behaviour all become the application's.

The bundle still adopts it into the boundary registry, so `forceFlush()` and `shutdown()` still
happen at the right moments and it is still handed to `Globals`. Nothing inside it is wrapped or
gated — the queueing, the boundary budget and the export gate are yours to rebuild.

## Guarantee matrix

| Override | Fail-open wrapper | Boundary lifecycle | Flush budget | Retry policy | Bundle's processors |
|---|---:|---:|---:|---:|---:|
| none | yes | yes | yes | yes | yes |
| sampler / id generator / span processors | yes | yes | yes | yes | yes |
| metric views | yes | yes | yes | yes | yes |
| transport family | yes | yes | no, for that family | no, for that family | yes |
| exporter | yes | yes | not guaranteed | not guaranteed | yes |
| provider | provider's own | yes, from outside | no | no | no |

## Choosing

- A **sampler**, **id generator**, **span processor** or **view** when the decision is inside the
  provider rather than around it. These keep every guarantee, and they are enough far more often
  than they are reached for.
- A **transport** when network mechanics are what you want to own: a custom client, an
  authentication scheme, a queue, an async handoff, your own circuit breaker.
- An **exporter** when the signal conversion or export behaviour must change but the bundle's
  provider lifecycle is still worth having.
- A **provider** only for full manual SDK construction — knowing that the queueing, the boundary
  budget and the export gate come with it.
