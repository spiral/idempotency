# Idempotency

The package protects operations from duplicated side-effects on retries in Spiral Framework
applications. It ships two mechanisms with different guarantees — an **AtLeastOnce** lease
(lock + fencing token + response cache) and an **ExactlyOnce-effect** transactional inbox —
behind one declarative `#[Idempotent]` attribute and one programmatic API.

## Installation

```bash
composer require spiral/idempotency
```

[![PHP](https://img.shields.io/packagist/php-v/spiral/idempotency.svg?style=flat-square&logo=php)](https://packagist.org/packages/spiral/idempotency)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/spiral/idempotency.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/spiral/idempotency)
[![License](https://img.shields.io/packagist/l/spiral/idempotency.svg?style=flat-square)](https://packagist.org/packages/spiral/idempotency)
[![Total downloads](https://img.shields.io/packagist/dt/spiral/idempotency.svg?style=flat-square)](https://packagist.org/packages/spiral/idempotency/stats)

Optional packages:

- `cycle/database` — the bundled storage driver (lease and inbox tables over a Cycle DBAL connection);
- `spiral/interceptors` — the declarative `#[Idempotent]` attribute via `IdempotencyInterceptor`
  (also needs a PSR-17 factory for the HTTP middleware);
- `spiral/queue` — the queue/jobs transport (`QueueIdempotencyBootloader`, `QueueKeyMiddleware`,
  `QueueRetryMiddleware`), making consumed jobs idempotent with `Locked → native job retry`;
- `spiral/events` — the PSR-14 events transport (`EventsIdempotencyBootloader`,
  `IdempotentListenerFactory`), making individual `#[Listener]` methods idempotent with a per-listener
  key;
- `predis/predis` — the default client for the Redis/Valkey lease backend (`RedisLeaseConfig`): AtLeastOnce
  storage over a Redis-compatible server, with server-side TTL (no GC needed) and atomic Lua CAS. Not
  needed when `Driver\Redis\RedisCommands` is bound to an adapter over the app's own Redis client;
- `spiral/cycle-bridge` — integrates the idempotency tables into the ORM schema
  (`cycle:sync` / `cycle:migrate`).

## Documentation

### Two guarantees

**Exactly-once delivery is impossible**: between committing a side-effect and recording its
completion there is always a crash window, so any retry may re-run the effect. What is achievable:

| Guarantee                | Mechanism                                                                                     | When                                                                      |
|--------------------------|-----------------------------------------------------------------------------------------------|---------------------------------------------------------------------------|
| **AtLeastOnce**          | Lease: atomic conditional insert + fencing-token CAS; the result is cached and replayed       | Always. The side-effect may repeat inside the crash window                |
| **AtMostOnce**           | Dedup-guard: the marker commits *before* the effect and is never removed, so a duplicate is **refused** (not re-run) | When losing the effect is safer than repeating it; a repeat is not safe to retry |
| **ExactlyOnce** (effect) | Inbox: `INSERT ... ON CONFLICT DO NOTHING` + the side-effect commit in **one DB transaction** | Only when the side-effect writes to the same database as the inbox record |

Use the lease for non-transactional effects (calling a payment gateway, sending an email) and the
inbox when the whole effect lives in your database (creating an order). The dedup-guard is for
fire-and-forget jobs/events where the effect must run **at most once** and a crash between marker and
effect may lose it. A duplicate is never re-run and never handed an invented answer: with
`cacheResult: true` it replays the stored result (a `null` result included), and when no outcome is
stored — the cache is off, or the first run has not written its result (it may still be running, or
it crashed or threw: the marker and the result are not committed atomically) — `execute()` throws
`Exception\OutcomeUnknownException`. Without the cache no outcome is ever stored; with it, a later
retry may replay one. Each transport maps that signal: HTTP `409 Conflict` without `Retry-After`, gRPC
`ABORTED` without a retry hint, a queue job is ACKed, an event listener is skipped.

> [!NOTE]
> Upgrading: a Cycle at-most-once row written by an earlier release for a first run that returned `null` with
> `cacheResult` on holds a NULL `result`, so its duplicate now answers as an unknown outcome instead of
> replaying `null`.

### Quick start

Register the bootloaders:

```php
// app/src/Application/Kernel.php
public function defineBootloaders(): array
{
    return [
        // ...
        \Spiral\Idempotency\Bootloader\IdempotencyBootloader::class,
        // opt-in: HTTP wiring for the #[Idempotent] attribute
        \Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader::class,
        // opt-in: idempotency tables in the ORM schema (cycle:sync / cycle:migrate)
        \Spiral\Idempotency\Bootloader\CycleSchemaBootloader::class,
    ];
}
```

Describe the storages and the HTTP middleware stack:

```php
// app/config/idempotency.php
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Http\HttpKeyMiddleware;
use Spiral\Idempotency\Http\HttpOutcomeMiddleware;

return [
    'default' => 'payments',

    // Resolution middleware, outer → inner. The outcome middleware sits OUTERMOST: it marshals
    // the response (replay headers, Locked → 409, missing key → 400); the key middleware inside
    // it extracts and normalizes the key.
    'transports' => [
        'http' => [
            HttpOutcomeMiddleware::class,
            HttpKeyMiddleware::class,
        ],
    ],

    // Semantic aliases: the handler code names an alias, the driver and the declared guarantee
    // live here. The declared guarantee is verified against the driver capability at bootstrap.
    'storages' => [
        'payments' => new CycleLeaseConfig(
            table: 'idempotency_lease',
            lockTtl: 30,        // seconds the PROCESSING lock is held
            retentionTtl: 3600, // seconds the cached result is kept
        ),
        'orders' => new CycleInboxConfig(
            table: 'idempotency_inbox',
        ),
    ],
];
```

`default` names the alias an `#[Idempotent]` without a `storage:` argument falls back to, so a
single-storage application never repeats the alias in business code. It is optional: omit it and
`storage:` becomes mandatory — an attribute that then omits it throws `MisconfigurationException`
before the action runs (never as a `4xx`/`5xx` response: a misconfiguration is not an outcome of the
call), and for an event listener already when the listener is registered.
A `default` pointing at an alias absent from `storages` fails at bootstrap, not on the first call.

`transports` is optional as a whole — omit it when no transport bootloader is registered (the
`IdempotencyRegistry::execute()` usage below). Once one is registered, its own entry becomes
mandatory: a missing `transports.<name>` throws `MisconfigurationException` on the first
`#[Idempotent]` call instead of running an empty pipeline that deduplicates on nothing. An explicit
`'http' => []` is valid — the key then comes only from the attribute — but an empty stack also drops
the transport's outcome mapping: the `Locked` → 409 / ABORTED / job re-enqueue and the AtMostOnce
duplicate → 409 / ABORTED / job ACK translations live in the outcome middleware (`HttpOutcomeMiddleware`,
`GrpcOutcomeMiddleware`, `QueueRetryMiddleware`). Without it those exceptions propagate unmapped — on
the queue, to Spiral's retry policy as plain failures. Keep at least the outcome middleware.

Storage drivers available under `storages`:

| Config                                | Guarantee   | Backend                                                                                                |
|---------------------------------------|-------------|--------------------------------------------------------------------------------------------------------|
| `Driver\Cycle\CycleLeaseConfig`       | AtLeastOnce | Lease table over a Cycle DBAL connection                                                                 |
| `Driver\Cycle\CycleInboxConfig`       | ExactlyOnce | Inbox table; the record and the side-effect commit in one transaction                                    |
| `Driver\Cycle\CycleAtMostOnceConfig`  | AtMostOnce  | Dedup-guard table                                                                                        |
| `Driver\Redis\RedisLeaseConfig`       | AtLeastOnce | Redis/Valkey hash with server-side TTL — no GC needed                                                    |
| `Driver\Memory\MemoryLeaseConfig`     | AtLeastOnce | Per-process PHP array. Dedup holds only inside the worker that acquired the lease and dies with it — for tests and local development, not for production traffic |

Add **one line** to your existing domain-core interceptor list — reference the
`IdempotencyInterceptor` alias, not a concrete class:

```php
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;

final class AppBootloader extends DomainBootloader
{
    protected const INTERCEPTORS = [
        // ...your existing interceptors (Cycle, Guard, ...) — innermost after auth:
        IdempotencyInterceptor::class,
    ];
}
```

Mark an action:

```php
use Spiral\Idempotency\Attribute\Idempotent;

final class PaymentController
{
    #[Route(route: '/payments/charge', methods: 'POST')]
    #[Idempotent(storage: 'payments')]
    public function charge(): ResponseInterface
    {
        // charge the gateway, return the response — it will be cached and replayed
    }
}
```

Create the tables with the project's normal workflow: `php app.php cycle:sync` (or generate a
migration with `cycle:migrate`).

> [!NOTE]
> Reference the `IdempotencyInterceptor` **alias**, with no scope. The `DomainBootloader`
> interceptor list is resolved in the root container, where the alias is a forwarding proxy: on every
> call it resolves the real, transport-flavored interceptor from the active dispatcher scope (the
> `http` scope, where `HttpIdempotencyBootloader` bound it; the `queue` scope for
> `QueueIdempotencyBootloader`). So the same domain core works in any scope, and every transport
> reuses the same alias. Referencing the concrete
> `PipelineIdempotencyInterceptor` class instead would fail — it is deliberately unbound in root. Invoking the
> proxy outside a transport scope fails fast with a friendly `MisconfigurationException`.

### HTTP behaviour

The client generates a key and sends it with every retry of the same operation:

```bash
curl -X POST /payments/charge -H 'Idempotency-Key: pay-42' -d 'amount=500'
```

| Situation | Response |
|---|---|
| First call | The action runs; the whole response is snapshotted. Headers: `Idempotency-Key`, `Idempotency-Replay: false` |
| Retry after completion | The cached response is replayed byte-identically, `Idempotency-Replay: true`; the action does **not** run |
| Retry while the first call is still in flight | `409 Conflict` + `Retry-After` (lease storages) |
| `AtMostOnce` duplicate with no stored outcome (`cacheResult` off; or on, and the first call is still running, crashed or threw before storing one) | `409 Conflict` **without** `Retry-After` — with `cacheResult` off no outcome is ever stored; with it on, a later retry may replay one — with `Idempotency-Key`, `Idempotency-Replay: true` and a JSON body (`"error": "outcome_unknown"`); the action does **not** run |
| No key supplied | `400 Bad Request` with a JSON body |
| The action responded `5xx` | Lease: not cached — the key is released and a retry re-runs the operation (configurable predicate of `HttpOutcomeMiddleware`). Inbox and `AtMostOnce` never re-run: the inbox (and `AtMostOnce` with `cacheResult`) stores the `5xx` and replays it; without `cacheResult` an `AtMostOnce` duplicate gets the `409` above |

By default `HttpKeyMiddleware` reads the `Idempotency-Key` header, then the `key` body/query field
(both names are constructor-configurable).

### Queue / Jobs behaviour

The same interceptor makes **queue/job handlers** idempotent — a broker delivers at-least-once, so a
redelivered job must not double-run its side-effect. Register the queue bootloader and list the queue
middleware under `transports.queue`:

```php
// Kernel::defineBootloaders()
\Spiral\Idempotency\Bootloader\QueueIdempotencyBootloader::class,
```

```php
// app/config/idempotency.php
use Spiral\Idempotency\Queue\QueueKeyMiddleware;
use Spiral\Idempotency\Queue\QueueRetryMiddleware;

'transports' => [
    'queue' => [
        QueueRetryMiddleware::class, // outer: maps failure modes back to the transport
        QueueKeyMiddleware::class,   // inner: extracts the key from the job header
    ],
],
```

Register the interceptor on the **consume** side (same alias, no concrete class) — either in
`app/config/queue.php` under `interceptors.consume`, or via the bootloader:

```php
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;

$queue->addConsumeInterceptor(IdempotencyInterceptor::class);
```

> [!IMPORTANT]
> Keep Spiral's default `RetryPolicyInterceptor` **outer** of the idempotency interceptor in the
> `consume` list: `QueueRetryMiddleware` re-throws a `Locked` collision as a `RetryableLockException`,
> and it is `RetryPolicyInterceptor` that catches it and re-enqueues the job with the carried delay.
> We reuse the framework's retry engine rather than reimplementing backoff.

A failing job **releases** its key (`FailurePolicy::Release` is the queue default), so the redelivery
the retry policy schedules actually re-runs the handler instead of replaying the cached failure. Pass
`failurePolicy: FailurePolicy::Cache` on the attribute for a job whose failure is a final answer.

Mark a job handler — the key comes from the **payload** via the attribute's `key` path, or from a
job **header** the producer set (`Options::withHeader('Idempotency-Key', ...)`):

```php
#[Idempotent(storage: 'orders', key: 'orderId')] // dot-path over the job payload
public function invoke(string $orderId, array $payload): void
{
    // runs once per orderId; a redelivered job replays / is skipped
}
```

When the producer is outside the application — a CDC pipeline publishing from a transactional outbox
table, say — the job carries no headers at all. Enable the **job-id fallback**: the broker job id
(the `id` argument of the consume call context) becomes the key material. Mind what it identifies: one
broker **message**, not the logical operation. The id is the same when the broker redelivers the message
(consumer crash, missing ack); a job re-published by a retry policy or pushed a second time by the
producer carries a new one, and whether a driver keeps the id across its own requeue is driver-specific.
To deduplicate the operation itself, keep a key from the payload (`key: 'payload.<field>'`) or the
header. The fallback is off by default so a producer that merely forgot the header does not get silent,
weaker deduplication:

```php
// a bootloader of the app — the config stack lists class names, so bind the configured instance
QueueKeyMiddleware::class => static fn(KeyResolver $resolver): QueueKeyMiddleware
    => new QueueKeyMiddleware($resolver, fallbackToJobId: true),
```

The header still wins when present, and the key scope (operation identity by default) still
namespaces the result, so two job types redelivered with the same broker id do not collide.

| Situation | Outcome |
|---|---|
| First delivery | The handler runs; the guarantee records the effect (inbox commit / lease + cache) |
| Redelivery after completion | Deduplicated — the handler does **not** re-run (ExactlyOnce/AtLeastOnce), the job is ACKed |
| Delivery while another worker holds the key | `LockedException` → `RetryableLockException` → the job is re-enqueued after the lock TTL (not dead-lettered) |
| No key resolvable (bad payload/header) | `MissingKeyException` propagates — **not** retryable, so the job dead-letters instead of retrying forever |
| `AtMostOnce` (dedup-guard) duplicate | The handler does **not** re-run and the job is ACKed (fire-once); this is the natural home for `AtMostOnce`. `QueueRetryMiddleware` turns the driver's `OutcomeUnknownException` into a `null` result, so the signal never reaches the retry policy — keep it in the stack |

For transient **failure** retries (infra errors), compose Spiral's own `#[RetryPolicy]` attribute on
the handler alongside `#[Idempotent]` — the two are orthogonal: idempotency dedups the effect, the
retry policy governs re-delivery.

A job whose entry point lives on an abstract base — the common "`handle()` implemented once, subclasses
implement `invoke()`" shape — annotates the **class** instead; there is no method of its own to mark:

```php
#[Idempotent(storage: 'orders')] // key from the job header
final class DispatchEvent extends JobHandler {} // handle() is inherited from JobHandler
```

### gRPC behaviour

The same interceptor makes **gRPC service methods** idempotent. Register the gRPC bootloader, list the
gRPC middleware under `transports.grpc`, and add the interceptor to `config/grpc.php`:

```php
// Kernel::defineBootloaders()
\Spiral\Idempotency\Bootloader\GrpcIdempotencyBootloader::class,
```

```php
// app/config/idempotency.php
use Spiral\Idempotency\Grpc\GrpcKeyMiddleware;
use Spiral\Idempotency\Grpc\GrpcOutcomeMiddleware;

'transports' => [
    'grpc' => [
        GrpcOutcomeMiddleware::class, // outer: response/status snapshot, Locked → ABORTED
        GrpcKeyMiddleware::class,     // inner: key from the `idempotency-key` metadata entry
    ],
],
```

The client sends the key as metadata; the server-side key middleware reads `idempotency-key`
(case-insensitively, configurable).

| Situation | Outcome |
|---|---|
| First call | The method runs; the protobuf response message is snapshotted. Response metadata: `idempotency-key`, `idempotency-replay: false` |
| Retry after completion | The cached message is rebuilt and returned with `idempotency-replay: true`; the method does **not** run |
| Retry while the first call is in flight | `ABORTED` — the status gRPC recommends for "retry at a higher level" (the analog of HTTP `409`) — with a `google.rpc.RetryInfo` detail carrying the suggested delay (the analog of `Retry-After`) |
| No key in the metadata | `INVALID_ARGUMENT` (the analog of HTTP `400`) |
| The method threw a `GRPCException` | The **status** is snapshotted (code + message + details) and replayed identically, exact subclass included |
| ... with a transient status (`UNAVAILABLE`, `DEADLINE_EXCEEDED`, `INTERNAL`, ...) | Lease: not cached — the key is released and a retry re-runs (configurable predicate of `GrpcOutcomeMiddleware`). Inbox and `AtMostOnce` never re-run: the inbox (and `AtMostOnce` with `cacheResult`) stores the status and replays it; without `cacheResult` an `AtMostOnce` duplicate gets the `ABORTED` above |
| `AtMostOnce` duplicate with no stored outcome (`cacheResult` off; or on, and the first call is still running, crashed or threw before storing one) | `ABORTED` **without** the `RetryInfo` detail — with `cacheResult` off no outcome is ever stored; with it on, a later retry may replay one — with `idempotency-replay: true`; the method does **not** run |
| A `null` outcome (void operation, first call or replay) | The method's **declared response type** is returned empty — gRPC has no "empty ACK", and the bridge's invoker requires a `Message` |

Unlike HTTP, no configuration is needed to keep a **failure** replay faithful: over gRPC a negative
outcome *is* a status, and `code` + `message` + `details` is a complete, deterministic snapshot of what
the client sees. Bind a `DomainFailureMapper` only if the service throws plain domain
exceptions instead of `GRPCException` — that mapping otherwise happens above the interceptor, too late
to be cached, and the replay would answer with a different status.

Infrastructure and Bug failures (including a `GRPCException` marked `Retryable`) are never
snapshotted: they stay exceptions so the key is released and a retry re-runs.

### Events behaviour

The same attribute makes **PSR-14 event listeners** idempotent, one listener method at a time. The
target is a durable fan-out: an event re-published by an outbox (or dispatched again by a redelivered
job) reaches several `#[Listener]` methods, and a failure in one of them must not re-run the others.

Register the events bootloader — there is no `transports.events` config section and no interceptor to
wire, because events are dispatched through PSR-14 rather than through a domain core:

```php
// Kernel::defineBootloaders()
\Spiral\Idempotency\Bootloader\EventsIdempotencyBootloader::class,
```

It replaces the framework's `ListenerFactoryInterface` with `IdempotentListenerFactory`, which wraps
each marked listener method in its storage. The factory is the integration point on purpose: the
dispatcher sees only an opaque list of closures, while the factory still knows *which* listener a
closure belongs to — the identity a per-listener key needs.

Give the event a stable identity by implementing `HasIdempotencyKey`, then mark the listeners:

```php
use Spiral\Idempotency\Events\HasIdempotencyKey;

final class OrderPlaced implements HasIdempotencyKey
{
    public function __construct(public readonly string $id) {} // outbox message_id, not a fresh uuid

    public function idempotencyKey(): string
    {
        return $this->id;
    }
}

final class PaymentService
{
    #[Listener]
    #[Idempotent(storage: 'events')]
    public function charge(OrderPlaced $event): void {}

    #[Listener]
    #[Idempotent(storage: 'events')]
    public function notify(OrderPlaced $event): void {}
}
```

An application whose events already share a base contract adopts the interface once, rather than per
event class:

```php
interface DomainEvent extends HasIdempotencyKey {}

trait WithEventId
{
    public readonly Uid $id; // the outbox message_id, preserved across re-publications

    public function idempotencyKey(): string
    {
        return $this->id->rawValue();
    }
}
```

Every event using the trait is then a valid target for a marked listener without a key path; keep the
identity the producer preserves, not one regenerated per dispatch.

The key can also come from the attribute's `key` arg-path, resolved over the single argument `event`
(`key: 'event.id'`) — use it for an event you do not own. An event with neither source throws
`MissingKeyException`: a listener marked idempotent must not deduplicate on nothing.

| Situation | Outcome |
|---|---|
| First dispatch | Every marked listener runs, each under its own key (scope = `ListenerClass::method`) |
| Re-dispatch of the same event | Each marked listener that completed is skipped; an unmarked one runs again |
| One listener of the fan-out threw | The dispatcher stops at it (PSR-14 semantics); on a lease or inbox storage the re-dispatch skips the listeners that completed and re-runs only the failed one. An `AtMostOnce` listener is never re-run — its key was consumed before it threw |
| Concurrent dispatch of the same event | `LockedException` propagates untouched — the durable producer that dispatched the event owns the retry |
| `AtMostOnce` duplicate with no stored outcome | The listener is skipped silently — its key is consumed whether the first run completed, failed or is still running; the fan-out goes on |
| Event with neither `key` path nor `HasIdempotencyKey` | `MissingKeyException` |

A failing listener **releases** its key (`FailurePolicy::Release` is the events default), which is what
makes the partial re-run work. Pass `failurePolicy: FailurePolicy::Cache` on the attribute for a
listener whose failure is a final answer.

> [!IMPORTANT]
> The event identity must be the one the producer preserves across re-publications of the same logical
> event — an outbox `message_id`, not a value regenerated per dispatch. A fresh id makes every
> redelivery look like a new event and silently disables deduplication.

### The `#[Idempotent]` attribute

```php
#[Idempotent(storage: 'payments', key: 'command.orderId', lockTtl: 60, ttl: 86400, scope: null)]
```

| Parameter | Meaning                                                                                                                                                          |
|-----------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `storage` | Semantic alias from the config — the only infrastructure reference in business code. Omit it to take the config's `default` alias                                 |
| `key`     | Dot-notation path over the **call arguments**, resolved by `ArgumentKeyResolver`: the leaf may be a non-boolean scalar, a `Stringable` (domain id) or a backed enum. `null` lets a transport middleware supply the key (HTTP header/field). A path that resolves to nothing fails fast |
| `lockTtl` | Override of the PROCESSING lock TTL, seconds (lease driver only)                                                                                                 |
| `ttl`     | Override of the completed-record retention TTL, seconds                                                                                                          |
| `scope`   | Key namespace, see below                                                                                                                                         |
| `failurePolicy` | What a thrown failure does to the key: `Cache` (replay it) or `Release` (free the key, the next call re-runs). `null` = the transport default, see below   |

The attribute goes on a **method** or on a **class**. On a class it covers **every** method the
transport dispatches to on that class, including ones inherited from an abstract base — the only way
to annotate a handler that never redeclares its entry point. On a job handler that is the single
`handle()`; on a controller it makes *every* action idempotent under the one storage alias, so reach
for a class attribute there only when that is what you mean. Resolution order, first hit wins:

1. the dispatched method;
2. the concrete class of the target;
3. its parents, nearest first.

So a method attribute beats a class one, and a subclass beats the base it inherits from.

Keys are namespaced by **operation identity** so that the same client key sent to two different
endpoints never replays a foreign response:

| `scope`                    | Key space                                                         |
|----------------------------|-------------------------------------------------------------------|
| `null` (default)           | `Controller::method` — safe per-operation isolation                |
| `'payment-flow'`           | Explicit name — intentionally shared by several endpoints         |
| `Idempotent::SCOPE_GLOBAL` | No namespacing — the client is responsible for global uniqueness  |

The default scope names the **concrete** class, not the one that declares the method, so sibling
subclasses sharing one inherited entry point get one key space each.

### ExactlyOnce: write through the transaction

The inbox driver opens a database transaction that carries both the dedup record and your
side-effect. Inside the operation, narrow the context to `CycleContext` and write through it —
this is the contract that makes the effect exactly-once:

```php
use Spiral\Idempotency\Driver\Cycle\CycleContext;
use Spiral\Idempotency\IdempotencyContext;

#[Idempotent(storage: 'orders', key: 'command.orderId')]
public function place(PlaceOrder $command, IdempotencyContext $ctx): array
{
    \assert($ctx instanceof CycleContext);

    // ORM entities: a scoped Unit of Work flushed inside the transaction
    $ctx->entityManager()->persist(new Order(...));
    // ...or raw DBAL through the transactional connection
    $ctx->database()->insert('order_events')->values([...])->run();

    return ['status' => 'placed'];
}
```

Crash before `COMMIT` → everything rolls back → a retry is clean. Crash after `COMMIT` → the retry
sees the dedup conflict, skips the operation and replays the stored result. No window.

> [!IMPORTANT]
> The guarantee only holds for effects written through the transactional connection. Anything
> external — an HTTP call, a message queue, another database — degrades the operation to
> AtLeastOnce no matter which driver runs it.

### Programmatic usage

When the key is already known, skip the attribute and call the driver directly:

```php
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;

public function __construct(
    private readonly IdempotencyRegistry $registry,
) {}

public function handle(string $transactionId): Receipt
{
    return $this->registry->get('payments')->execute(
        $transactionId,
        static fn(IdempotencyContext $ctx): Receipt => /* the operation */,
        new ExecuteOptions(lockTtl: 60, ttl: 86400, failurePolicy: FailurePolicy::Cache),
    );
}
```

A replayed `null` is a stored outcome. An `AtMostOnce` storage with no stored outcome throws
`Exception\OutcomeUnknownException` (carrying the `key`, and `final` — true when no outcome can ever appear) instead of returning `null`; catch it where
"already consumed, answer unknown" needs handling. Without `cacheResult` that holds for good; with it,
the first run may still be running, and a later retry may replay its result. A lease storage still throws `LockedException` while
another call holds the key.

The programmatic path applies no automatic namespacing — compose the final key yourself
(`KeyResolver` is available as a service and supports `parentKey` hierarchies for
composing multi-step chains).

### Failure classification

An exception thrown by the operation is classified into one of three kinds — deterministic outcome
and "will a retry help" are independent axes:

| Kind               | Default mapping                                                                   | Lease reaction                                        |
|--------------------|-----------------------------------------------------------------------------------|-------------------------------------------------------|
| **Domain**         | any other `\Exception`, a `RetryableExceptionInterface` that is **not** retryable, or a **final** `OutcomeUnknownException` | Cached as a valid negative outcome, replayed on retry |
| **Infrastructure** | `\Error`, `\Exception` implementing `Retryable`, a retryable `RetryableExceptionInterface`, a `LockedException`, or a non-final `OutcomeUnknownException` | The key is released; the transport/client retries     |
| **Bug**            | Only by explicit configuration (`DefaultFailureClassifier(bugExceptions: [...])`) | The key is released; no re-enqueue — report and fix   |

A `LockedException` or `OutcomeUnknownException` reaching the classifier comes from a **nested**
idempotent call inside the operation and describes the inner key's state. A lock is transient
contention: caching it would replay it for the whole retention TTL after the inner lock is gone, so the
outer key is released and a retry re-runs. An unknown outcome depends on `$final`, set by the inner
storage: `true` (AtMostOnce with `cacheResult` off) means the inner key answers the same on every call,
so re-running the outer operation would only repeat its other effects — it is cached as Domain;
`false` (`cacheResult` on, the inner result may still be stored) releases the outer key like a lock.

`RetryableExceptionInterface` is `spiral/queue`'s own retry contract: a job exception that already
states its retry intent for the broker is read the same way here, so it needs no second marker. The
rule is skipped when `spiral/queue` is not installed. It is checked **before** the `\Error` rule, so an
`\Error` implementing the contract follows `isRetryable()` rather than its type — the one combination
whose classification changed in 0.4.

A cached domain failure is replayed as `CachedDomainFailureException` carrying the original class
name and message. For an **exact-type** replay, implement `ReplayableFailure` on the
domain exception:

```php
final class PaymentDeclined extends \DomainException implements ReplayableFailure
{
    public function toReplayPayload(): array
    {
        return ['code' => $this->code, 'reason' => $this->reason];
    }

    public static function fromReplayPayload(array $payload): static
    {
        return new self($payload['code'], $payload['reason']);
    }
}
```

#### Per-operation failure policy

Classification decides *what kind* of failure happened; `FailurePolicy` decides whether the operation
may run again under the same key at all:

| Policy | Effect on a thrown failure |
|---|---|
| `Cache` | The table above applies: a Domain failure becomes a cached negative outcome and is replayed |
| `Release` | Any failure frees the key and the original throwable is rethrown unchanged — the next call re-runs the operation |

The default comes from the transport, and follows who owns the retry: **queue → `Release`** (the broker
redelivers the job), **HTTP and gRPC → `Cache`** (the client repeats the call itself and must see the
same answer). A direct `execute()` without options also caches. Override per operation on the attribute
or in `ExecuteOptions`:

```php
use Spiral\Idempotency\FailurePolicy;

#[Idempotent(storage: 'jobs', key: 'event.id', failurePolicy: FailurePolicy::Release)]
```

This is a **lease/AtLeastOnce** contract. The inbox (ExactlyOnce) and at-most-once drivers ignore it:
an inbox failure already rolls the dedup row back with the side-effect, and a committed record of
either driver is terminal — releasing it would let the effect run twice.

#### Consistent HTTP status for a thrown domain failure

> [!WARNING]
> A *thrown* domain failure bypasses the response snapshot, so the two attempts are rendered by
> different code: the first by your exception handler, the replay from the cached snapshot. Over HTTP
> that means a **different status code** — unless you do one of the three things below.

Three ways to keep the replay identical to the first attempt, best first:

1. **Return an error response** instead of throwing — it is snapshotted and replayed byte-identically,
   status included. Nothing to configure.
2. **Bind a `DomainFailureRenderer`** — keeps the throwing style: `HttpOutcomeMiddleware`
   renders Domain-classified throwables into a response *inside* the operation, so the outcome is
   cached as a response snapshot and the replay carries the same status **and** the
   `Idempotency-Replay` header:

   ```php
   final class DeclineRenderer implements DomainFailureRenderer
   {
       public function __construct(private ResponseFactoryInterface $responses) {}

       public function render(\Throwable $failure): ?ResponseInterface
       {
           // Return null for failures this renderer does not own — they are rethrown untouched.
           return $failure instanceof PaymentDeclined
               ? $this->responses->createResponse(402)
               : null;
       }
   }
   ```

   Bind it in a bootloader (`DomainFailureRenderer::class => DeclineRenderer::class`) — the
   middleware picks it up by autowiring. Only `FailureKind::Domain` failures reach the renderer:
   Infrastructure and Bug ones stay exceptions, so the key is still released and a retry re-runs. The
   `cacheable` predicate still applies, so a rendered 5xx is not cached on a lease either. Rows written *before*
   the renderer was bound keep replaying as a throw.
3. **Map `CachedDomainFailureException::$originalClass`** in the application exception handler.

### Customization

- **Middleware** — both pipelines are open: implement `ResolutionMiddleware` (transport phase,
  key extraction / outcome mapping) and list it under `transports.<name>`, or
  `ExecutionMiddleware` (domain phase around the operation).
- **Serializer** — cached results are serialized with `spiral/serializer` (`PhpSerializer` by
  default); bind your own `SerializerInterface` to switch, e.g. to JSON.
- **Classifier** — bind `FailureClassifier` to replace the default failure mapping.
- **Domain failure rendering** — bind `DomainFailureRenderer` to turn thrown domain failures
  into cached HTTP responses, so a replay reproduces the same status (see above).
- **Key policy** — bind `KeyResolver` to change normalization, hashing and hierarchy
  composition, or `ArgumentKeyResolver` to change how the attribute's `key` path is walked
  and which leaf types have a string form.
- **Schema** — role names of the generated ORM tables are customizable via
  `SchemaNaming`; table names live in the storage configs.

> [!IMPORTANT]
> The default `PhpSerializer` unserializes blobs read from the idempotency tables. The trust
> boundary is the table itself: if several services or roles can write to that database, bind a
> JSON serializer and keep the operation results JSON-safe.
