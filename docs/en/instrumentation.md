# Instrumentation

What each component produces, what it costs, and which of its options are worth changing. Every
key with its default lives in [`config/example_config.yaml`](../../config/example_config.yaml).

Telemetry follows OpenTelemetry semantic conventions `1.44.0`, published as the scope's
`schema_url`.

## How components are configured

Each component is one node with two signals:

```yaml
open_telemetry:
    instrumentation:
        http_server:
            traces: true
            metrics: true
```

Two master switches sit above them. With `traces.enabled: false` or `metrics.enabled: false`, no
component produces that signal, whatever its own key says.

Where a signal needs options of its own, the same key takes a long form, and the short form
normalises into it. This exists for one real asymmetry: a health check is noise in a trace and
load in a metric.

```yaml
http_server:
    traces:
        enabled: true
        excluded_paths: ['/_profiler', '/_wdt', '/health']
    metrics:
        enabled: true
        excluded_paths: ['/_profiler', '/_wdt']
```

A component wires itself only when its Symfony component is actually installed, checked against
what a `--no-dev` install will contain rather than against `class_exists()` today.

Every component that records a duration also takes `duration_buckets`; see
[Histogram boundaries](#histogram-boundaries).

## HTTP server

*Defaults: traces on, metrics on.*

| | |
|---|---|
| Spans | one server span per main request, named `{method} {route}` — for example `GET /orders/{id}` |
| Attributes | `http.request.method`, `http.route`, `http.response.status_code`, plus `client.address` and `user.*` when opted in |
| Metrics | `http.server.request.duration`, request and response body size |

```yaml
http_server:
    excluded_paths: ['/_profiler', '/_wdt', '/health']
    record_client_ip: false
    record_user_id: false
    record_user_roles: false
    record_exception_min_status: 500
```

Span names use route templates rather than raw paths, because `/orders/{id}` is one series and
`/orders/17` is a million.

A 4xx leaves the span status unset. The conventions put the error threshold at 500, and the span
follows them. `record_exception_min_status` controls only when the exception is recorded as a
span event.

### The authenticated user

With `symfony/security-core` installed, the server span can carry who made the request. There are
two keys, because these are two different decisions.

`user.id` names a person. A trace carrying it is personal data, with retention, access and
erasure obligations attached, and a tracing backend is rarely governed as tightly as the
application's own database.

`user.roles` names a group, and answers the question usually asked of a trace: was this slow for
admins, or for everyone. Most applications can turn this one on.

The value is `UserInterface::getUserIdentifier()` as it is, not hashed. A hash salted by this
bundle would be neither reversible for support nor stable across deployments, and applications
that compute a real one have `user.hash` for it.

It is written on `kernel.controller`, because at the priority the server span opens at the
firewall has not authenticated yet. Only the main request is annotated: a sub-request runs inside
the same trace, and two identities on one trace are worse than none.

The token comes from `security.untracked_token_storage` and is read only once there is a span to
write it to. `security.token_storage` tracks reads, and a single read behind a lazy firewall
increments the session usage index. `AbstractSessionListener` answers that with
`Cache-Control: private, must-revalidate` and `max-age=0` on every response, including the ones
an application deliberately made public. Turning this key on does not change what your
application sends.

## HTTP client

*Defaults: traces on, metrics on.*

| | |
|---|---|
| Spans | one client span per request, named by method |
| Propagation | trace context added to outgoing headers, so the callee continues the trace |
| Metrics | `http.client.request.duration`, request and response body size |

```yaml
http_client:
    excluded_hosts: ['otel-collector', 'alloy']
```

Lazy and streamed responses are handled by the instrumentation lifecycle: the span ends when the
response is actually complete, not when the client returns.

Exclude the collector, or telemetry instruments its own export. Use the hostname the application
really calls: excluding `localhost` does nothing when the endpoint is `http://alloy:4318`.

## Doctrine DBAL

*Defaults: traces on, metrics on. Requires DBAL 4.*

| | |
|---|---|
| Spans | one per statement, named by a summary of the SQL; optionally one per transaction boundary |
| Metrics | `db.client.operation.duration` |

```yaml
doctrine:
    query_text: false
    only_with_parent: true
    transactions: true
```

`query_text` records the statement as `db.query.text`. It is off by default because raw SQL
carries literals, and literals carry data about people.

`only_with_parent` keeps query spans out of traces that have no parent operation. It exists for
the Messenger transport that polls the database every idle second: those queries are real load,
so the metrics record them either way, but as traces they are thousands of orphans a day and
nothing else.

`transactions` makes `BEGIN`, `COMMIT` and `ROLLBACK` operations of their own, one span per round
trip, labelled by `db.operation.name`. Without it, a slow or failing commit is folded into
whatever statement happened to precede it. Nested transactions are savepoint statements and are
traced as statements.

## Messenger

*Defaults: traces on, metrics on.*

| | |
|---|---|
| Spans | `symfony.messenger.dispatch {Message}` on dispatch, a producer span per send, a consumer span per processed message |
| Propagation | trace context travels on the message as a stamp, so producing and consuming are one trace |
| Metrics | messages sent, messages consumed, `messaging.process.duration` |

Dispatch is deliberately not measured as a messaging client operation. A dispatch may validate,
open a transaction and run a handler in-process without a broker being involved at all, and
folding that into send percentiles would make a synchronous bus appear to send messages it never
sent. The real send is measured per transport, inside the dispatch span.

The unit of work is the message, not the worker. See [Console](#console) for why.

## Console

*Defaults: traces on, metrics off.*

| | |
|---|---|
| Spans | one per command, carrying the exit code and any error |
| Metrics | `console.command.duration`, with `metrics: true` |

```yaml
console:
    excluded_commands: ['cache:clear', 'cache:warmup', 'assets:install', 'lint:container', 'lint:yaml']
```

Setting the list replaces these defaults but never the Messenger workers: `messenger:consume` and
`messenger:consume-messages` are always excluded. A span covering a whole worker process is never
exported, because spans export when they end and that end is the process exiting. It also
swallows every message into one unbounded trace, and it makes `doctrine.only_with_parent`
useless, since an always-active span makes idle polling look like work inside a unit of work.

## Cache

*Defaults: traces on, metrics on.*

| | |
|---|---|
| Spans | one per pool operation, named `cache.{operation}` |
| Metrics | `cache.operation.duration`, `cache.lookup.count` with a hit/miss attribute |

```yaml
cache:
    pools: ['*']
    excluded_pools:
        - cache.system
        - cache.validator
        - cache.serializer
        - cache.property_info
        - cache.messenger.restart_workers_signal
```

`['*']` takes every supported pool tagged `cache.pool`, and `[]` takes none. The excluded defaults
are the framework's own pools: infrastructure, not application behaviour.

## Serializer

*Defaults: traces on, metrics off.*

| | |
|---|---|
| Spans | `serializer.{operation}` |
| Metrics | `serializer.operation.duration`, with `metrics: true` |

Spans require an existing trace. Work outside one still contributes to the duration metric, which
is what happens when Messenger decodes a message before consumption begins. Metrics are off by
default because serialization is usually visible enough inside the span that contains it.

## Mailer

*Defaults: traces on, metrics off.*

| | |
|---|---|
| Spans | one per transport send |
| Metrics | `mailer.send.duration`, with `metrics: true` |

```yaml
mailer:
    record_subject: false
```

Subjects are user-generated content and frequently name a person or an order.

## Scheduler

*Defaults: traces on, metrics off.*

| | |
|---|---|
| Spans | `scheduler.run` around a scheduled task inside message processing, with `scheduler.schedule.name`, `scheduler.task.id` and the message type |
| Metrics | `scheduler.task.duration`, with `metrics: true` |

## Monolog

Two independent things:

```yaml
open_telemetry:
    logs:
        correlation:
            enabled: true     # trace_id and span_id on every record
        export:
            enabled: false    # the records themselves, over OTLP
```

Correlation is what makes a log line findable from a trace and a trace findable from a line.
Export is a second destination for every log line; see
[Configuration](configuration.md#correlate-and-export-logs).

Everything the bundle reports about itself goes to the `open_telemetry` Monolog channel, and so
does the OpenTelemetry SDK's own output. Without that, the SDK falls back to `error_log()`, and
"the collector refused the batch" lands outside the application's logging while the bundle's own
reports land inside it. That channel is never exported over OTLP, whatever
`logs.export.excluded_channels` says.

## PHP runtime

*Defaults: metrics on. No traces: there is no operation here, only state.*

| | |
|---|---|
| Metrics | `php.memory.usage` (the Zend allocator heap) and `php.worker.uptime`, sampled at each export |

In worker mode this is the only place a slow leak is visible, because a collector scraping the
host sees one long-lived process rather than its heap. Workers are told apart by
`service.instance.id` on the resource, not by a label on these metrics.

Only processes that serve work repeatedly report them, such as an HTTP worker or a Messenger
consume loop. A one-shot console command never starts: its two-second lifetime is noise in a
series meant to show a slow climb.

## Histogram boundaries

Every component that records a duration takes `duration_buckets`, in seconds:

```yaml
open_telemetry:
    instrumentation:
        doctrine:
            duration_buckets: [0.0005, 0.001, 0.005, 0.01, 0.05, 0.25]
```

The defaults follow the semantic conventions, which are chosen to be comparable across services
rather than tight around any one of them. An SLO stated in single-digit milliseconds is invisible
in buckets whose second step is 10 ms.

Boundaries must be strictly increasing and greater than zero. Anything else is rejected when the
container compiles, because the SDK would build the buckets as given and the histogram would
quietly stop meaning anything. An empty list keeps the defaults. The unit stays seconds: a
histogram whose unit varies by component cannot be compared across components.

For what this cannot reach — an instrument the bundle did not create, or an attribute key whose
cardinality has to be cut — use a [metric view](sdk-customization.md#add-a-metric-view).

## Response propagation

Nothing is written back to the caller by default. Set `OTEL_EXPERIMENTAL_RESPONSE_PROPAGATORS` and
install a propagator package that registers itself with the SDK, whose own registry ships only
`none`. The bundle then adds the resulting headers to the main request's response.

Sub-requests are not propagated into. Their response is rendered into the page rather than sent,
and doing it per sub-request would overwrite the main request's header with an internal span. The
header names the server span, not the caller's.

The upstream contract is marked experimental; the bundle wires it deliberately.

## Sensitive and high-cardinality data

Trace attributes and metric labels deserve different answers. A request, order or user id in a
trace may be exactly what an investigation needs. The same id in a metric attribute is a new time
series, forever.

The bundle never copies a span attribute into a metric label. Turning any capture on changes what
a trace carries and never how many series exist.

Every capture that can identify a person is opt-in, each behind its own key, so the decision is
made once per kind rather than once for all of them:

| Key | Records | Default |
|---|---|---|
| `doctrine.query_text` | `db.query.text` — raw SQL, literals included | off |
| `http_server.record_client_ip` | `client.address` | off |
| `http_server.record_user_id` | `user.id` from the authenticated token | off |
| `http_server.record_user_roles` | `user.roles` — a group, not a person | off |
| `mailer.record_subject` | mail subjects, which are user-generated content | off |

Before turning one on, decide where traces are stored, who can read them and how long they are
kept. A tracing backend is rarely governed as tightly as the application's own database, and a
trace is not usually covered by the retention policy written for that database.
