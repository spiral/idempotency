# Usage — spiral/idempotency

How to make a handler idempotent once the package is set up (see `setup.md` if it is not).

## 0. Discover the available storages first

The `storage:` argument of `#[Idempotent]` must name an alias declared in
`app/config/idempotency.php` (or be omitted, taking the config's `default` alias), and the alias
determines the guarantee your handler gets. Before writing the attribute, list what the project
declares:

```bash
php <this-skill-dir>/scripts/list-storages.php --root=.
```

It prints every alias with its driver class, guarantee (AtLeastOnce / AtMostOnce / ExactlyOnce),
table/prefix, TTLs, plus the default alias and the configured transports. If the script cannot
load the config (e.g. it needs the app container), read `app/config/idempotency.php` directly.

Match the alias's guarantee to the operation (see the guarantee table in `SKILL.md`). If no
existing alias fits — e.g. the handler needs ExactlyOnce but only lease aliases exist — add a new
storage to the config rather than misusing a wrong-guarantee alias.

## The `#[Idempotent]` attribute

```php
use Spiral\Idempotency\Attribute\Idempotent;

#[Idempotent(storage: 'payments', key: 'command.orderId', lockTtl: 60, ttl: 86400, scope: null)]
```

- `storage` — alias from config; the only infrastructure reference in business code. Omit it and the
  config's `default` alias is used; with no `default` configured, an omitted `storage` throws
  `MisconfigurationException` — before the action runs, so it never becomes a transport response, and
  for an event listener already at registration.
- `key` — dot-path over the **call arguments**, walked by `ArgumentKeyResolver`; the leaf may be a
  non-boolean scalar, a `Stringable` (UUID/ULID value objects) or a backed enum. `null` lets the transport
  middleware supply it (HTTP header/field, job header, gRPC metadata). A path resolving to nothing —
  or to a value with no string form (array, plain object, null, bool) — fails fast.
- `lockTtl` / `ttl` — per-operation overrides (lockTtl: lease driver only; the inbox ignores it —
  its mutual exclusion is the row lock of the in-progress INSERT, not a time-bound lease).
- `failurePolicy` — `FailurePolicy::Cache` (a Domain failure becomes a cached, replayed outcome) or
  `FailurePolicy::Release` (any failure frees the key, the original throwable is rethrown and the next
  call re-runs). `null` = the transport default: queue and events `Release`, HTTP/gRPC `Cache`. Lease storages
  only — see [`failures-and-gc.md`](failures-and-gc.md).
- `scope` — key namespace: `null` (default) = per-operation `Class::method` isolation, where the
  class is the **concrete** target, not the one declaring the method; `'name'` = deliberately
  shared across endpoints (e.g. an HTTP endpoint and a queue job that are the same logical
  operation); `Idempotent::SCOPE_GLOBAL` = no namespacing, the client owns global uniqueness.

### Method or class

The attribute targets a method **or** a class. On a class it covers **every** method the transport
dispatches to on that class — including ones inherited from an abstract base, the only way to
annotate a handler that never redeclares its entry point. On a job handler that is the single
`handle()`; on a controller every action becomes idempotent under the one storage alias. Resolution
takes the first hit of: the dispatched method, the concrete class, then its parents nearest-first. A
method attribute therefore beats a class one, and a subclass beats its base.

```php
abstract class JobHandler
{
    public function handle(string $name, string $id, mixed $payload, array $headers = []): void
    {
        // implemented once; subclasses only implement invoke()
    }
}

#[Idempotent(storage: 'jobs')] // key from the job header — no method of its own to mark
final class DispatchEvent extends JobHandler
{
    public function invoke(mixed $payload): void {}
}
```

Sibling subclasses sharing one inherited `handle()` still get one key space each, because the
default scope names the concrete class.

## HTTP

Client sends `Idempotency-Key: <key>` (header, or `key` body/query field — both names are
`HttpKeyMiddleware` constructor params):

```bash
curl -X POST /payments/charge -H 'Idempotency-Key: pay-42' -d 'amount=500'
```

| Situation | Response |
|---|---|
| First call | Runs; whole response snapshotted. `Idempotency-Key` + `Idempotency-Replay: false` headers |
| Retry after completion | Cached response replayed byte-identically, `Idempotency-Replay: true`; the action does **not** run |
| Concurrent retry (first call in flight) | `409 Conflict` + `Retry-After` (lease storages) |
| No key supplied | `400 Bad Request` with a JSON body |
| Action responded `5xx` | **Not cached**: key released, a retry re-runs (the `cacheable` predicate of `HttpOutcomeMiddleware`) |

## Queue / jobs

Key comes from the payload via the attribute's `key` dot-path, or from a job header the producer
set: `Options::withHeader('Idempotency-Key', ...)`.

```php
#[Idempotent(storage: 'orders', key: 'orderId')] // dot-path over the job payload
public function invoke(string $orderId, array $payload): void
{
    // runs once per orderId; a redelivered job replays / is skipped
}
```

Producers outside the application (a CDC pipeline publishing from a transactional outbox table) set
no headers. `new QueueKeyMiddleware($resolver, fallbackToJobId: true)` then derives the key from the
broker job id — the `id` argument of the consume call context. The id identifies one broker message,
not the operation: same on broker redelivery (crash, missing ack), new when a retry policy re-publishes
the job or the producer pushes it again; id preservation across a driver's own requeue is
driver-specific. Deduplicating the operation still needs a payload or header key. The header still wins
when present; the key scope keeps job types apart. Off by default so a producer that forgot the header
does not get silent, weaker deduplication. The `transports` stack lists class names, so bind the
configured instance in a bootloader.

| Situation | Outcome |
|---|---|
| First delivery | Handler runs; the guarantee records the effect |
| Redelivery after completion | Deduplicated — the handler does **not** re-run; the job is ACKed |
| Another worker holds the key | `LockedException` → `RetryableLockException` → re-enqueued after the lock TTL (not dead-lettered) |
| No key resolvable | `MissingKeyException` — **not** retryable, the job dead-letters instead of retrying forever |
| `AtMostOnce` duplicate | Handler returns `null` → job ACKed (fire-once) — the natural home for AtMostOnce |

Ordering rule: Spiral's `RetryPolicyInterceptor` must stay **outer** of the idempotency
interceptor in the consume list — it is what catches `RetryableLockException` and re-enqueues with
the carried delay. The package never reimplements retry/backoff. For transient-failure retries,
compose Spiral's own `#[RetryPolicy]` on the handler — orthogonal to idempotency.

## gRPC

Key from the `idempotency-key` metadata entry (case-insensitive; configurable via
`GrpcKeyMiddleware`).

| Situation | Outcome |
|---|---|
| First call | Runs; the protobuf response message is snapshotted. Metadata: `idempotency-key`, `idempotency-replay: false` |
| Retry after completion | Cached message rebuilt, `idempotency-replay: true`; the method does **not** run |
| Concurrent retry | `ABORTED` + `google.rpc.RetryInfo` detail with the suggested delay |
| No key in metadata | `INVALID_ARGUMENT` |
| Method threw a `GRPCException` | The status (code + message + details) is snapshotted and replayed, exact subclass included |
| ...with a transient status (`UNAVAILABLE`, `DEADLINE_EXCEEDED`, `INTERNAL`, ...) | Not cached: key released, a retry re-runs (predicate of `GrpcOutcomeMiddleware`) |
| Dedup hit with no cached message (AtMostOnce duplicate / void op) | The method's declared response type is returned empty |

Bind `Grpc\DomainFailureMapper` only if the service throws plain domain exceptions
instead of `GRPCException` — otherwise that mapping happens above the interceptor, too late to be
cached, and a replay would answer with a different status.

## Events (PSR-14 listeners)

`EventsIdempotencyBootloader` replaces the framework's `ListenerFactoryInterface`, so each
`#[Idempotent]` listener **method** gets its own key — a fan-out where one listener throws re-runs
only that one on the next dispatch. No `transports.events` section and no interceptor: events go
through PSR-14, not a domain core.

Key material, in order: the attribute's `key` path — resolved over the single argument `event`, so it
starts with that name (`key: 'event.id'`) — otherwise `Events\HasIdempotencyKey::idempotencyKey()` on
the event. Neither present throws `MissingKeyException`.

```php
use Spiral\Idempotency\Events\HasIdempotencyKey;

final class OrderPlaced implements HasIdempotencyKey
{
    public function __construct(public readonly string $id) {}

    public function idempotencyKey(): string
    {
        return $this->id; // the outbox message_id — stable across re-publications
    }
}

#[Listener]
#[Idempotent(storage: 'events')]
public function charge(OrderPlaced $event): void {}
```

An application whose events share a base contract adopts the interface once — the base interface
extends `HasIdempotencyKey` and the shared trait implements `idempotencyKey()` from the id the producer
already put on the event (see the events section of the README for the shape). Every event using the
trait is then a valid target for a marked listener without a key path; keep the identity the producer
preserves, not one regenerated per dispatch.

| Situation | Outcome |
|---|---|
| First dispatch | Every marked listener runs under its own key (scope = `ListenerClass::method`) |
| Re-dispatch | Completed marked listeners are skipped; unmarked ones run again |
| One listener threw | The dispatcher stops at it; the re-dispatch skips the completed ones and re-runs only the failed one (`FailurePolicy::Release` is the events default) |
| Concurrent dispatch | `LockedException` propagates untouched — the durable producer owns the retry |
| No `key` path and no `HasIdempotencyKey` | `MissingKeyException` |

The identity must be the one the producer preserves across re-publications of the same logical event
(an outbox `message_id`). A value regenerated per dispatch makes every redelivery look new and
silently disables deduplication.

## ExactlyOnce contract: write through the transaction

The inbox opens a DB transaction carrying both the dedup record and the side-effect. Inside the
operation, narrow the context and write **through it** — this is what makes the effect
exactly-once:

```php
use Spiral\Idempotency\Driver\Cycle\CycleContext;
use Spiral\Idempotency\IdempotencyContext;

#[Idempotent(storage: 'orders', key: 'command.orderId')]
public function place(PlaceOrder $command, IdempotencyContext $ctx): array
{
    \assert($ctx instanceof CycleContext);
    $ctx->entityManager()->persist(new Order(...));          // ORM: scoped UoW, flushed in-tx
    $ctx->database()->insert('order_events')->values([...])->run(); // or raw DBAL
    return ['status' => 'placed'];
}
```

Crash before `COMMIT` → everything rolls back → a retry is clean. Crash after `COMMIT` → the retry
sees the dedup conflict and replays the stored result. No window.

Anything written **outside** that connection (HTTP call, queue message, another database) degrades
the operation to AtLeastOnce regardless of the driver. The inbox transaction is `Exclusive` by
default: it **throws if an outer transaction is already open** — do not wrap the handler in your
own transaction.

## Programmatic API

When the key is already known, skip the attribute:

```php
use Spiral\Idempotency\{ExecuteOptions, FailurePolicy, IdempotencyContext, IdempotencyRegistry};

$this->registry->get('payments')->execute(
    $transactionId,
    static fn(IdempotencyContext $ctx): Receipt => /* the operation */,
    new ExecuteOptions(lockTtl: 60, ttl: 86400, failurePolicy: FailurePolicy::Cache),
);
```

No automatic namespacing here — compose the final key yourself (`KeyResolver` is a
service and supports `parentKey` hierarchies for multi-step chains).

## Long-running operations

Call `$ctx->renew()` at safe points to keep the lease's PROCESSING lock alive. Unforced calls are
throttled by the storage's `heartbeatThreshold`; `renew(force: true)` renews unconditionally
(e.g. right after a long blocking step). It is a no-op on inbox/at-most-once drivers and never
throws. Renewal is cooperative: an operation blocked in one opaque call must reach a renew
checkpoint or finish within its `lockTtl`.
