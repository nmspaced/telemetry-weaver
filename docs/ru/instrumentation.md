# Тонкости instrumentation

Здесь собраны неочевидные defaults. Полный список параметров находится в [`config/example_config.yaml`](../../config/example_config.yaml).

## HTTP server

- Route templates используются вместо raw paths, чтобы контролировать cardinality.
- Client IP по умолчанию не записывается.
- 4xx автоматически не считаются server error; 5xx считаются.
- Profiler/debug/health paths можно исключать отдельно для traces и metrics.

Body-size и другие HTTP metrics зависят от доступной instrumentation; перед массовым включением optional measurements учитывайте cardinality и стоимость backend.

## Symfony HttpClient

Outgoing context propagates автоматически; lifecycle поддерживает lazy/streaming responses. Исключите hostname Collector/Alloy, чтобы telemetry export не трассировал сам себя:

```yaml
open_telemetry:
    instrumentation:
        http_client:
            excluded_hosts: ['otel-collector', 'alloy']
```

Нужно исключать hostname, который реально видит приложение. `localhost` не поможет, если endpoint — `http://alloy:4318`.

## Doctrine DBAL

`query_text` выключен по умолчанию, потому что raw SQL может содержать literals и персональные данные.

`only_with_parent: true` убирает background polling queries из traces, но metrics всё равно измеряют реальную нагрузку на DB. Это особенно полезно для Messenger transport, который poll'ит базу в idle.

`transactions: true` инструментирует BEGIN/COMMIT/ROLLBACK, чтобы slow/failed COMMIT не терялся за предыдущим statement.

## Messenger

Long-running Messenger commands исключены из whole-command tracing. Один span на весь `messenger:consume` был бы бесконечным и сделал бы idle polling частью одной огромной операции.

Осмысленная единица работы — message. Dispatch/send/process instrumentation отвечает за propagation и processing metrics.

## Console

Обычные конечные commands можно трассировать. Messenger worker commands остаются исключёнными даже при замене `excluded_commands`.

## Runtime metrics

`php.memory.usage` и `php.worker.uptime` предназначены для processes, которые обслуживают работу повторно. Для обычной one-shot CLI команды или request-scoped FPM pipeline это не meaningful worker-state series.

## Logs

Trace/span correlation не зависит от OTLP log export. Если logs уже уходят через Loki/Filebeat/systemd и т.п., `logs.export.enabled: false` — нормальный default.

При включённом OTLP log export Telemetry Weaver использует batch processor с выключенным auto-flush и дренирует его на execution boundaries. Размеры очереди и schedule берутся из `OTEL_BLRP_*` variables.

## Sensitive и high-cardinality data

Span attributes и metric labels требуют разного подхода. Request/order/user ID в trace может быть полезен; тот же ID в metric attribute создаёт новую time series.

Не включайте SQL text, mail subject и client address без явной privacy/retention policy.
