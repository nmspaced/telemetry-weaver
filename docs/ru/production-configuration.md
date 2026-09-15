# Production configuration

Telemetry Weaver намеренно разделяет конфигурацию на два слоя:

- **`OTEL_*` / `OTEL_PHP_*` environment variables** настраивают OpenTelemetry SDK и exporters: endpoint, protocol, sampling, queue sizes, resource detectors, OTLP headers и compression.
- **`open_telemetry.yaml`** настраивает Symfony instrumentation и Weaver-specific lifecycle/resilience.

Если стандартная OpenTelemetry variable уже существует, не стоит дублировать её отдельным Symfony-параметром.

## 1. Предпочитайте локальный Collector / Alloy

Рекомендуемая production-схема — **agent pattern** OpenTelemetry Collector: application SDK → Collector на том же host/sidecar/локальной сети → удалённый backend.

Плюсы:

- application-facing OTLP latency маленькая и предсказуемая;
- remote retries/queues не блокируют PHP;
- backend credentials можно хранить в Collector, а не в каждом приложении;
- один local endpoint может развести traces, metrics и logs по разным remote systems;
- outage backend становится проблемой Collector, а не request/worker.

Прямой export в удалённый SaaS поддерживается, но увеличивайте timeout осознанно: Weaver может ограничить стандартный transport, но не убрать сам remote network round trip.

Официальный reference: [OpenTelemetry Collector agent deployment pattern](https://opentelemetry.io/docs/collector/deploy/agent/).

## 2. HTTP/protobuf или gRPC

### HTTP/protobuf

Самый простой default:

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Нужен обычный OTLP exporter и PSR HTTP client implementation; README использует Symfony HttpClient + Nyholm PSR-7.

### gRPC

Используйте gRPC, если инфраструктура уже его использует или вам нужен именно этот transport:

```bash
composer require open-telemetry/transport-grpc
```

Пакету требуется `ext-grpc`.

```dotenv
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4317
```

Budget semantics те же: каждый OTLP send получает текущий allowance destination как deadline. Реальный gRPC path стоит проверять в production image, особенно TLS/mTLS и recovery после сетевых ошибок.

## 3. Transport timeout и boundary budget — разные вещи

`OTEL_EXPORTER_OTLP_TIMEOUT` — максимальный timeout одного обычного OTLP send. `sdk.export.flush_timeout_ms` — **общий deadline execution boundary** в Weaver.

Пример:

```dotenv
OTEL_EXPORTER_OTLP_TIMEOUT=500
```

```yaml
open_telemetry:
    sdk:
        export:
            flush_timeout_ms: 1000
```

Внутри boundary стандартный transport использует минимум из configured timeout и текущего destination allowance. При нескольких collectors общий 1000 ms budget делится, а не умножается.

Для local Collector разумно начать с transport timeout 250–500 ms и total boundary budget 500–1000 ms, затем измерять. Remote endpoint может требовать больше, но если remote Collector регулярно требует секунды, обычно правильнее приблизить Collector к приложению, чем дать PHP большой wait budget.

## 4. Retry — вне PHP

Оставляйте:

```yaml
open_telemetry:
    sdk:
        export:
            max_retries: 0
```

Standard PHP transport retry'ит синхронно. При недоступном Collector один failed export превращается в дополнительные sleep/timeout внутри application process.

`Sending_queue` и retry лучше настраивать в Collector exporters.

## 5. Trace processor и очередь

Рекомендуемый вариант:

```dotenv
OTEL_PHP_TRACES_PROCESSOR=batch
OTEL_BSP_SCHEDULE_DELAY=1000
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512
```

Telemetry Weaver строит batch processor с **выключенным auto-flush**. `span->end()` только кладёт span в очередь; очередь дренируется на controlled boundaries с schedule delay как cadence.

Если очередь заполнится до boundary, spans могут быть потеряны. Это намеренный trade-off: bounded loss безопаснее unbounded queue или network wait в business code.

`OTEL_PHP_TRACES_PROCESSOR=simple` — сознательный opt-out: simple processor экспортирует на span end и возвращает network latency в application path.

## 6. Metrics

В long-lived workers metrics экспортируются на execution boundaries; если bundle `metrics.flush_interval_ms` равен `null`, `OTEL_METRIC_EXPORT_INTERVAL` используется как default минимального cadence.

```dotenv
OTEL_METRIC_EXPORT_INTERVAL=15000
```

Metric temporality следует настроенному preference, но Weaver учитывает instrument kind. Можно использовать:

```dotenv
OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta
```

если backend/Collector рассчитан на delta counters/histograms. UpDown/state instruments остаются cumulative в selector Weaver.

Если delta не нужен — оставьте SDK default, не включайте его «на всякий случай».

## 7. FPM request metrics

У request-based pipeline новый MeterProvider создаётся на каждый request. Cumulative counters поэтому начинались бы заново каждый request и образовывали misleading stream.

Request metrics по умолчанию выключены:

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: disabled
```

Включайте только если понимаете downstream model:

```yaml
open_telemetry:
    runtime:
        request_metrics:
            mode: delta
```

В этом режиме synchronous counters/histograms становятся delta на request; state-like instruments остаются cumulative и описывают короткоживущий pipeline конкретного request.

Требования:

- Resource должен различать writers (`service.instance.id` или `process.pid` + host/container identity);
- нельзя задавать один общий `service.instance.id=${HOSTNAME}` всем FPM children;
- не ограничивайте `OTEL_PHP_DETECTORS` только `env`, если отдельно не даёте уникальную writer identity;
- downstream должен понимать независимые short-lived delta sequences. Stateful delta-to-cumulative processor может считать каждый request reset'ом, поэтому Collector pipeline нужно проверить до массового включения.

Явный безопасный detector set, например:

```dotenv
OTEL_PHP_DETECTORS=env,host,process,process_runtime,sdk
```

SDK default `all` тоже подходит; главный риск — случайно убрать host/process identity.

## 8. Worker identity и `APP_RUNTIME_MODE`

Long-lived workers получают стабильный автоматический `service.instance.id`. Обычно не задавайте его вручную, если не можете гарантировать уникальность на concurrent worker.

Symfony runtime mode сообщает Weaver, переживает ли provider один request. FrankenPHP интегрируется с этой моделью. В custom RoadRunner/bootstrap integration проверьте resolved Symfony parameters; shared HTTP worker должен фактически выглядеть как `web=1&worker=1`.

Если runtime adapter не выставляет это сам, `APP_RUNTIME_MODE=web=1&worker=1` — ожидаемая форма для shared-kernel HTTP worker.

## 9. Logs

Trace/span correlation включается отдельно от OTLP log export.

Если logs уже уходят другим pipeline, оставляйте:

```dotenv
OTEL_LOGS_EXPORTER=none
```

```yaml
open_telemetry:
    logs:
        export:
            enabled: false
```

Для OTLP logs:

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

Bundle всегда строит OTLP log path с batch processor и выключенным auto-flush, поэтому log emission сам по себе не должен ждать Collector.

## 10. Один endpoint или разные

Самая простая схема — один local Collector:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Для OTLP/HTTP SDK добавляет `/v1/traces`, `/v1/metrics` и `/v1/logs` к base endpoint.

Per-signal endpoints используются **как есть**, поэтому путь signal нужно указывать полностью:

```dotenv
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://mimir:4318/v1/metrics
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://loki:4318/v1/logs
```

В default transport Weaver destinations дедуплицируются по origin (`scheme://host:port`). Разные paths одного Collector делят один budget; разные origins получают независимые shares одного global boundary deadline.

## 11. Headers и authentication

Предпочитайте стандартные OTLP headers:

```dotenv
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20token
```

`open_telemetry.sdk.exporter_otlp_headers` может переопределить/добавить headers для стандартного Weaver OTLP transport; `null` удаляет header из env-derived набора.

Custom transport family обходит этот Weaver merge и сама отвечает за authentication/configuration.

## 12. Sampling

Типичный production starting point:

```dotenv
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1
```

Правильное значение зависит от трафика, требований к incident/debug и стоимости backend. Sampling стоит настроить **до** попыток компенсировать лишний объём уменьшением очередей.

В local development `always_on` удобен, если нужны полные traces.

## Полные примеры

- [`config/examples/worker.env`](../../config/examples/worker.env)
- [`config/examples/fpm.env`](../../config/examples/fpm.env)
- [`config/examples/grpc.env`](../../config/examples/grpc.env)
- [`config/examples/split-endpoints.env`](../../config/examples/split-endpoints.env)
- [`config/examples/worker.yaml`](../../config/examples/worker.yaml)
- [`config/examples/fpm.yaml`](../../config/examples/fpm.yaml)
- [полный reference bundle config](../../config/example_config.yaml)

OpenTelemetry references:

- [PHP SDK configuration](https://opentelemetry.io/docs/languages/php/sdk/)
- [OTLP exporter configuration](https://opentelemetry.io/docs/languages/sdk-configuration/otlp-exporter/)
- [Collector agent deployment pattern](https://opentelemetry.io/docs/collector/deploy/agent/)
