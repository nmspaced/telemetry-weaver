# Instrumentation notes

This page documents the less obvious defaults. The exhaustive configuration keys live in [`config/example_config.yaml`](../../config/example_config.yaml).

## HTTP server

- Route templates are preferred over raw paths to control cardinality.
- Client IP recording is disabled by default.
- 4xx responses are not automatically server errors; 5xx are.
- Profiler/debug/health paths can be excluded independently for traces and metrics.

Body-size and other HTTP metrics follow what the installed instrumentation exposes; review the full configuration and your backend cardinality/cost before enabling optional measurements broadly.

## Symfony HttpClient

Outgoing context is propagated and lazy/streaming responses are handled by the instrumentation lifecycle. Exclude the Collector/Alloy hostname so telemetry export does not instrument itself:

```yaml
open_telemetry:
    instrumentation:
        http_client:
            excluded_hosts: ['otel-collector', 'alloy']
```

Use the hostname the application actually sees. Excluding `localhost` does nothing if the endpoint is `http://alloy:4318`.

## Doctrine DBAL

`query_text` is off by default because raw SQL can contain literals and personal data.

`only_with_parent: true` keeps background polling queries out of traces while metrics still measure the database load. This is particularly useful for Messenger transports that poll the database while idle.

`transactions: true` instruments BEGIN/COMMIT/ROLLBACK boundaries so slow or failed commits are visible rather than folded into the preceding statement.

## Messenger

Long-running Messenger commands are excluded from whole-command tracing. One span for an entire `messenger:consume` process would be unbounded and would make idle polling appear to be inside one giant unit of work.

The meaningful unit is the message. Dispatch/send/process instrumentation owns message propagation and processing metrics.

## Console

Normal finite commands can be traced. Messenger worker commands remain excluded even if the user replaces `excluded_commands`.

## Runtime metrics

`php.memory.usage` and `php.worker.uptime` are for processes that serve work repeatedly. They are not emitted as meaningful worker-state series for ordinary one-shot commands or request-scoped FPM pipelines.

## Logs

Trace/span correlation is independent from OTLP log export. Keeping `logs.export.enabled: false` is reasonable when logs already flow through Loki/Filebeat/systemd/etc.

When OTLP log export is enabled, Telemetry Weaver uses a batch processor with auto-flush disabled and drains it at execution boundaries. Queue/schedule values come from `OTEL_BLRP_*` variables.

## Sensitive and high-cardinality data

Treat trace attributes and metric labels differently. A request/order/user ID in a trace may be useful; the same ID in a metric attribute creates a new time series.

Do not enable SQL text, mail subjects or client addresses without considering privacy and retention policy.
