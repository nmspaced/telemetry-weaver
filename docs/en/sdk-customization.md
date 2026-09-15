# SDK customization

Telemetry Weaver has opinionated defaults but intentionally exposes escape hatches through Symfony DI.

## Layers

```text
default
provider → resilient exporter → budget-aware OTLP transport

transport override
provider → resilient exporter → application transport

exporter override
provider → resilient wrapper → application exporter

provider override
application provider
```

## Transport family override

HTTP and gRPC are configured independently:

```yaml
open_telemetry:
    sdk:
        otlp:
            transport_factories:
                grpc: app.telemetry.grpc_transport_factory
                http: app.telemetry.http_transport_factory
```

The service must implement `OpenTelemetry\SDK\Common\Export\TransportFactoryInterface`.

The HTTP family covers the HTTP OTLP protocols supported by the installed SDK (`http/protobuf`, `http/json`, and where available `http/ndjson`). gRPC is a separate family because a generic factory cannot reliably infer gRPC vs HTTP/protobuf from the regular factory arguments.

A family left `null` continues to use Weaver's budget-aware transport. A custom family bypasses Weaver's transport layer:

- no destination budget for that family;
- no Weaver retry override;
- no `sdk.exporter_otlp_headers` merge;
- the transport's own timeout/retry semantics apply.

The resilient exporter above the transport still catches failures and respects the export gate.

The upstream OTLP exporter still resolves the selected protocol through the OpenTelemetry Registry. A custom factory does not remove the requirement that the installed SDK knows that protocol.

## Exporter override

```yaml
open_telemetry:
    sdk:
        traces:
            exporter: app.telemetry.span_exporter
```

The service must implement the SDK exporter interface for the signal. The standard Weaver provider still uses it, and Weaver still adds its resilient exporter wrapper / export gate. Request-metric policy is still applied to a custom metrics exporter.

However, an arbitrary exporter is not preemptible. If its `export()` blocks for ten seconds, `flush_timeout_ms` cannot forcibly stop that PHP code.

A provider and exporter cannot both be configured for the same signal.

## Provider override

```yaml
open_telemetry:
    sdk:
        traces:
            provider: app.telemetry.tracer_provider
```

A custom provider replaces the complete signal SDK pipeline: processors, exporter, transport, auto-flush and internal timeout behavior belong to the application.

Weaver still adopts the provider into its execution-boundary registry and calls the appropriate `forceFlush()` / `shutdown()` lifecycle. Nothing inside the provider is wrapped or gated.

## Guarantee matrix

| Override | Fail-open wrapper | Weaver boundary lifecycle | Destination budget | Weaver retry policy | Weaver processors |
|---|---:|---:|---:|---:|---:|
| none | yes | yes | yes | yes | yes |
| transport family | yes | yes | no for that family | no for that family | yes |
| exporter | yes | yes | not guaranteed | not guaranteed | yes |
| provider | provider-owned | yes, externally | no | no | no |

## When to use which override

- Replace **transport** when network mechanics are the thing you want to own: a custom client, authentication scheme, queue, async handoff, custom budget or circuit breaker.
- Replace **exporter** when the OpenTelemetry signal conversion/export behavior must change but the Weaver provider lifecycle is still useful.
- Replace **provider** only when you need full manual SDK construction.
