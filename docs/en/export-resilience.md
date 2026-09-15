# Export resilience and destination budgets

The default export pipeline is designed around one rule: **telemetry may be lost; application availability must not be lost with it**.

## What is bounded

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

`flush_timeout_ms` is a deadline for the whole boundary, not a timeout multiplied by the number of signals.

Telemetry Weaver cannot preempt arbitrary PHP code. The hard bound relies on the standard transport honoring the timeout it receives. A custom transport/exporter/provider may block longer; this is an explicit escape hatch.

## Why the budget is keyed by destination

A failure domain is usually a Collector endpoint, not a signal.

```text
traces ─┐
logs   ─┴─→ https://collector-a:4318/v1/...

metrics ──→ https://collector-b:4318/v1/metrics
```

For the default transport, the budget key is the endpoint origin: `scheme://host:port`. Paths such as `/v1/traces` and `/v1/metrics` on the same origin share one destination.

This gives two useful properties:

- traces and logs cannot each spend a full fresh timeout against the same hung Collector;
- a different, healthy Collector still gets a fair chance.

## Work-conserving shares

A share is fixed when a destination is first used during that flush. It is computed from the time left and the destinations not yet served.

Unused time is not reserved forever. If destination A receives 300 ms but finishes in 40 ms, the remaining time can increase the share available to later destinations.

Destinations for which a transport exists but no batch is eventually sent still count conservatively. This may end a flush earlier, never later.

## Exhaustion and minimum useful allowance

A destination is exhausted for the current flush when it consumes its share or a send behaves like a timeout.

An allowance below the implementation's small minimum is not granted at all. Network clients operate on millisecond-scale deadlines; starting a request with only a tiny remainder creates noise rather than useful work.

Exhaustion applies only to the current boundary unless cooldown is also started.

## Conclusive timeout and cooldown

Not every failed send proves that a Collector is unhealthy.

A fast `ECONNREFUSED`, a quick 4xx, or a signal-specific payload error is cheap and may say nothing about another signal using the same destination. A timeout-like failure is stronger evidence.

Destination cooldown therefore starts only when:

1. the send had a meaningful allowance (at least a configured fraction of an even split of the whole boundary budget);
2. it consumed almost all of that allowance;
3. it failed.

This avoids punishing a healthy destination that received only scraps after another destination spent most of the boundary.

Signal-level failure cooldown and destination-level cooldown are intentionally separate. A signal can fail without proving that the whole Collector is unreachable.

## Final flush

Scheduled boundaries respect cooldown and normal flush intervals. A final shutdown path is different: it makes one last attempt even for cooling destinations, but it still shares the same global budget.

Forced process termination cannot guarantee delivery.

## Retry policy

The default application-side retry count is zero. Retry inside PHP is synchronous in the standard transport and can turn one failed export into repeated sleeps and timeouts in the application process.

For durable delivery use a local Collector/Alloy and configure queues/retries there.

## Multiple endpoints

Per-signal OTLP endpoints work naturally with the destination budget:

```dotenv
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://mimir:4318/v1/metrics
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://loki:4318/v1/logs
```

Three different origins are three budget destinations. Different paths on one origin are one destination.

See also [production configuration](production-configuration.md).
