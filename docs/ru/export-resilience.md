# Export resilience и budgets по destination

Default export pipeline строится вокруг одного правила: **telemetry можно потерять; availability приложения нельзя терять вместе с ней**.

## Что именно ограничивает budget

```text
instrumentation
    ↓
bounded SDK queues
    ↓
execution boundary
    ↓
one global flush deadline
    ↓
destination-aware shares
    ↓
OTLP transport
```

`flush_timeout_ms` — deadline всей boundary, а не timeout, умноженный на количество signals.

Telemetry Weaver не может прервать произвольный PHP-код. Жёсткая граница времени работает потому, что стандартный transport соблюдает переданный ему timeout. Custom transport/exporter/provider может блокироваться дольше — это сознательный escape hatch.

## Почему budget считается по destination

Failure domain обычно является Collector endpoint, а не signal.

```text
traces ─┐
logs   ─┴─→ https://collector-a:4318/v1/...

metrics ──→ https://collector-b:4318/v1/metrics
```

Для стандартного transport ключ budget — origin endpoint: `scheme://host:port`. Пути `/v1/traces` и `/v1/metrics` на одном origin относятся к одному destination.

Это даёт два важных свойства:

- traces и logs не могут каждый заново потратить полный timeout на одном зависшем Collector;
- независимый здоровый Collector всё равно получает справедливый шанс.

## Work-conserving shares

Share фиксируется при первом использовании destination в текущем flush. Размер вычисляется из оставшегося времени и количества destinations, которые ещё не были обслужены.

Неиспользованное время не резервируется навсегда. Если destination A получил 300 ms, но закончил за 40 ms, остаток может увеличить share последующих destinations.

Destinations, для которых transport создан, но batch в итоге не появился, учитываются консервативно. Это может закончить flush раньше, но не позже deadline.

## Exhaustion и минимально полезный allowance

Destination исчерпывается для текущего flush, если он потратил свою долю или send ведёт себя как timeout.

Слишком маленький allowance вообще не выдаётся. Network clients работают с deadline порядка миллисекунд; запуск запроса на совсем маленьком остатке создаёт только ложные timeout'ы.

Само exhaustion действует только в текущей boundary, если отдельно не запущен cooldown.

## Conclusive timeout и cooldown

Не каждый failed send доказывает, что Collector нездоров.

Быстрый `ECONNREFUSED`, быстрый 4xx или signal-specific payload error дешёвы и не обязательно говорят что-либо о другом signal на том же destination. Timeout-like failure — более сильное свидетельство.

Поэтому destination cooldown запускается только когда:

1. send получил достаточно большой allowance (не меньше заданной доли от равномерного split всего boundary budget);
2. использовал почти весь allowance;
3. завершился ошибкой.

Так здоровый destination не уходит на 30 секунд в cooldown только потому, что ему достался маленький остаток после чужой задержки.

Signal-level и destination-level cooldown разделены намеренно: ошибка одного signal не всегда означает недоступность всего Collector.

## Final flush

Обычные scheduled boundaries учитывают cooldown и interval. Final shutdown делает последнюю попытку даже для cooling destinations, но всё равно живёт внутри того же global budget.

Принудительное завершение процесса не может гарантировать доставку.

## Retry policy

Application-side retries по умолчанию выключены. В стандартном PHP transport retry синхронный и может превратить одну ошибку в серию sleep + timeout внутри application process.

Durable delivery лучше обеспечивать локальным Collector/Alloy и настраивать queues/retries уже там.

## Несколько endpoints

Per-signal OTLP endpoints естественно работают с destination budget:

```dotenv
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://mimir:4318/v1/metrics
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://loki:4318/v1/logs
```

Три разных origin — три budget destinations. Разные paths одного origin — один destination.

См. также [production configuration](production-configuration.md).
