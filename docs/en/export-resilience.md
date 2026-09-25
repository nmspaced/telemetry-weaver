# Export resilience

One rule decides the whole export design: **telemetry may be lost; the application's
availability may not be lost with it.**

Everything below follows from that. Nothing here is a durable delivery mechanism. That is the
collector's job, and it is the reason
[a local collector](configuration.md#export-to-a-local-collector) is the recommended topology.

## Where export happens

Spans and log records never export from the code that produced them. `span->end()` and a log call
put a record on a bounded queue and return. The queue is drained at an execution boundary: the end
of an HTTP request, a finished console command, a Messenger worker between messages, or PHP
shutdown.

```text
instrumentation and application code
        │  enqueue, never send
        ▼
   bounded SDK queues
        │  drained at an execution boundary
        ▼
   one deadline for the whole boundary
        │  shared between collectors
        ▼
      OTLP transports
```

A boundary exports a queue on the SDK's schedule: once `OTEL_BSP_SCHEDULE_DELAY` (or
`OTEL_BLRP_SCHEDULE_DELAY`) has passed, or as soon as the queue holds a full batch of
`OTEL_BSP_MAX_EXPORT_BATCH_SIZE` (`OTEL_BLRP_MAX_EXPORT_BATCH_SIZE`) records. A busy worker therefore
exports every few requests instead of letting its queue overflow between two scheduled flushes.

A queue can still fill inside one unit of work, such as a single request, message or command that
produces more records than the queue holds. The excess is dropped, not exported inline: bounded
loss is a better failure than an unbounded queue or a network wait inside business code. The next
boundary logs how many records were dropped on the `open_telemetry` channel. Queue sizes are
`OTEL_BSP_MAX_QUEUE_SIZE` and `OTEL_BLRP_MAX_QUEUE_SIZE`, and they apply per worker.

Metrics differ only in that there is nothing to enqueue. They are collected and exported at the
boundary too, no more often than `metrics.flush_interval_ms` or `OTEL_METRIC_EXPORT_INTERVAL`.

## One deadline per boundary

```yaml
open_telemetry:
    sdk:
        export:
            flush_timeout_ms: 1000
```

`flush_timeout_ms` bounds the whole boundary, whatever it has to send. It is a total, not a
per-signal timeout: three signals do not mean three seconds.

This number decides how much of a request telemetry may consume in the worst case, so choose it
rather than inherit it.

## The budget is divided between collectors, not signals

Collectors fail, and signals do not.

```text
traces ─┐
logs   ─┴─→ collector-a:4318   (hung)

metrics ──→ collector-b:4318   (healthy)
```

Telemetry sent to one collector shares that collector's fate, and telemetry sent to a different
one must not be held hostage by it. So the budget is keyed by the endpoint's origin,
`scheme://host:port`. Paths on the same origin, such as `/v1/traces` and `/v1/metrics`, are one
collector, as they are in reality.

Without this, the hung collector in the diagram takes a full timeout for traces and another for
logs, and the metrics bound for a healthy collector arrive late or not at all.

Two properties follow:

- **No collector can be charged twice.** Once a send to a collector times out, everything else
  bound for it during that boundary is refused immediately instead of waiting out the same
  timeout again.
- **A slow collector cannot starve a healthy one.** Each gets a share of the deadline: the time
  left divided by the collectors not yet tried.

Shares are not reservations. A collector that answers in 40 ms out of a 300 ms share leaves the
remaining 260 ms available to the ones after it. When less than a few milliseconds are left, no
send is started at all, because a network call with that deadline produces noise rather than
telemetry.

## Cooldown: skipping a collector that is down

```yaml
open_telemetry:
    sdk:
        export:
            failure_cooldown_ms: 30000
```

A collector that timed out is skipped by scheduled boundaries for this long, so a worker does not
rediscover the same outage on every single request.

Not every failure earns a cooldown. A connection refused, a quick 4xx or a rejected payload is
cheap and says little about the collector's health, often nothing at all about another signal
using it. A timeout is stronger evidence, but only if the send actually had time to work with. A
collector that received nothing but the scraps another one left behind has not been shown to be
unhealthy, and should not sit out thirty seconds for someone else's delay.

So a cooldown starts when a send had a meaningful share of the boundary, spent essentially all of
it, and failed.

Signal-level backoff and collector-level cooldown remain separate. A signal can fail on its own,
on a payload the backend rejects, without proving the collector unreachable.

## The final flush

Scheduled boundaries respect cooldowns and flush intervals. The last flush of a process does not.
It makes one attempt even at a cooling collector, because there will be no later boundary to try
again from. It still runs inside the same deadline.

A process killed with `SIGKILL`, or dying on a fatal error, delivers nothing. No PHP library can
promise otherwise.

## Retries

```yaml
open_telemetry:
    sdk:
        export:
            max_retries: 0      # the default
            retry_delay_ms: 100
```

The standard PHP transport retries synchronously, sleeping in the calling process between
attempts. When a collector is down, each retry costs another timeout plus backoff, inside a worker
that should be serving traffic.

Retry belongs where it is asynchronous: the collector's `sending_queue` and `retry_on_failure`.

## What the budget cannot do

The deadline works by giving each send the smaller of its configured timeout and the destination's
remaining share. It cannot preempt PHP code that ignores it.

- A **custom transport factory** owns its own timeout behaviour. If it blocks for ten seconds, the
  boundary takes ten seconds.
- A **custom exporter** is likewise not interruptible.
- A **custom provider** replaces the pipeline entirely, including the queueing and the budget.

Those are documented escape hatches rather than accidents. See
[SDK customization](sdk-customization.md) for what each override keeps and gives up.

## Multiple backends

Per-signal endpoints work naturally with this model:

```dotenv
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://tempo:4318/v1/traces
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://mimir:4318/v1/metrics
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://loki:4318/v1/logs
```

Three origins are three independent failure domains sharing one deadline. Tempo being down delays
traces and does not delay metrics.

Sending all three to one local collector is still the simpler and usually better arrangement: one
failure domain the application can reach in a millisecond, with the fan-out to Tempo, Mimir and
Loki happening in a process that is allowed to wait.

See also [Configuration](configuration.md).
