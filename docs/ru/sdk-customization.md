# SDK customization

У Telemetry Weaver opinionated defaults, но через Symfony DI намеренно доступны escape hatches.

## Уровни подмены

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

## Подмена transport family

HTTP и gRPC настраиваются независимо:

```yaml
open_telemetry:
    sdk:
        otlp:
            transport_factories:
                grpc: app.telemetry.grpc_transport_factory
                http: app.telemetry.http_transport_factory
```

Service должен реализовывать `OpenTelemetry\SDK\Common\Export\TransportFactoryInterface`.

HTTP family охватывает HTTP OTLP protocols установленного SDK (`http/protobuf`, `http/json` и, если поддерживается, `http/ndjson`). gRPC — отдельная family, потому что generic factory не может надёжно отличить gRPC от HTTP/protobuf только по стандартным аргументам factory.

Family со значением `null` продолжает использовать budget-aware transport Weaver. Custom family обходит transport layer Weaver:

- destination budget для неё не применяется;
- Weaver retry override не применяется;
- `sdk.exporter_otlp_headers` не merge'ится;
- действуют timeout/retry semantics самого transport.

Resilient exporter выше transport остаётся: он перехватывает failure и учитывает export gate.

Upstream OTLP exporter всё ещё разрешает выбранный protocol через OpenTelemetry Registry. Custom factory не отменяет требование, чтобы установленный SDK знал этот protocol.

## Подмена exporter

```yaml
open_telemetry:
    sdk:
        traces:
            exporter: app.telemetry.span_exporter
```

Service должен реализовывать SDK exporter interface соответствующего signal. Стандартный provider Weaver продолжает его использовать, а Weaver добавляет resilient exporter wrapper / export gate. Request-metric policy также остаётся вокруг custom metrics exporter.

Но произвольный exporter нельзя preempt'нуть. Если его `export()` блокируется десять секунд, `flush_timeout_ms` не сможет насильно остановить этот PHP-код.

Для одного signal нельзя одновременно задать и provider, и exporter.

## Подмена provider

```yaml
open_telemetry:
    sdk:
        traces:
            provider: app.telemetry.tracer_provider
```

Custom provider заменяет весь signal SDK pipeline: processors, exporter, transport, auto-flush и внутренние timeout'ы теперь принадлежат приложению.

Weaver всё ещё принимает provider в execution-boundary registry и вызывает нужный `forceFlush()` / `shutdown()`. Всё внутри provider уже не оборачивается и не gate'ится.

## Матрица гарантий

| Override | Fail-open wrapper | Weaver boundary lifecycle | Destination budget | Weaver retry policy | Weaver processors |
|---|---:|---:|---:|---:|---:|
| нет | да | да | да | да | да |
| transport family | да | да | нет для этой family | нет для этой family | да |
| exporter | да | да | не гарантируется | не гарантируется | да |
| provider | ответственность provider | да, снаружи | нет | нет | нет |

## Что подменять

- **Transport** — если нужно владеть сетевой механикой: custom client, authentication, очередь, async handoff, свой budget или circuit breaker.
- **Exporter** — если нужно изменить export behavior конкретного signal, но сохранить provider lifecycle Weaver.
- **Provider** — только если нужен полностью manual SDK setup.
