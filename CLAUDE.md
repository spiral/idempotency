# spiral/idempotency — agent notes

Idempotency guarantees over Cycle DBAL/ORM: **AtLeastOnce** (lease + fencing-token CAS) and
**ExactlyOnce** (inbox: `INSERT ... ON CONFLICT DO NOTHING` + side-effect in one DB transaction).
Cycle is an *optional* driver — core (`src/` outside `Driver/Cycle`) must carry no `Cycle\*` types.

Transports are optional too: their packages are `require-dev` + `suggest`, and their types stay
confined to the transport adapter dir. HTTP (`src/Http`, `psr/http-message`) and Queue (`src/Queue`,
`spiral/queue`) each bind a `transport:`-flavored `PipelineIdempotencyInterceptor` in their dispatcher scope
(`http` / `queue`) under the shared `IdempotencyInterceptor` alias.

gRPC (`src/Grpc`) imports only `spiral/roadrunner-grpc` (+ `google/protobuf`, `google/common-protos`)
types, but the integration it plugs into is `spiral/roadrunner-bridge` — a **behavioural** dependency
with no imported type: its `GRPC\Internal\Invoker` builds
`CallContext(Target::fromPair($service, $method->name), [$grpcContext, $message])` (hence the key
middleware reading `getArguments()[0]`, and `#[Idempotent]` being discoverable at all — `fromPair()`
with a service *instance* yields a real `ReflectionMethod`), its result MUST be a protobuf `Message`
(hence materializing an empty response for a `null` outcome), and its dispatcher scope is `grpc`.
The bridge is `require-dev` **for tests only**: `tests/Unit/Grpc/BridgeIntegrationTest.php` drives the
real `Invoker` so a change in any of those assumptions fails a test instead of silently disabling
idempotency in production.

Events (`src/Events`, `spiral/events`) is the odd transport out: no interceptor, no `transports.*`
config stack. `EventsIdempotencyBootloader` overrides the `ListenerFactoryInterface` binding (hence
its `EventsBootloader` dependency — only a later binding wins) with `IdempotentListenerFactory`,
which wraps each `#[Idempotent]` listener method. The factory, not a dispatcher decorator, is the
integration point: it is the only place that still knows which listener a closure belongs to, which
is what a per-listener key needs. Queue: `spiral/queue`
types appear only in `src/Queue/RetryableLockException` (adapts `Locked` → the native
`RetryableExceptionInterface` so `RetryPolicyInterceptor` re-enqueues) and in
`Internal\Pipeline\DefaultFailureClassifier`, which reads that same `RetryableExceptionInterface`
behind an `interface_exists()` guard because it runs on every transport; the key/retry middleware use
only `spiral/interceptors`. We do NOT reimplement retry/backoff — Spiral's engine owns it.

## Shipped AI skill — keep it in sync

`skills/spiral-idempotency/` is an AI skill this package ships to consumer projects (picked up by
the `llm/skills` Composer plugin discovery). When you add a feature or change public behaviour —
attribute/config parameters, bootloaders, transport semantics (HTTP/queue/gRPC responses, statuses,
headers), failure classification, GC policy — update the skill too: `SKILL.md`, the relevant
`references/*.md`, the `scripts/*.php` if the package/config surface they inspect changed, and
`assets/*` (migration + SQL DDL) whenever `Driver\Cycle\CycleSchema` changes — the assets mirror
its column layout.
Same rule as the README: the skill documents the contract, so a behaviour change without a skill
update ships stale guidance to every consumer.

## Testing

Two suites (see `testo.php`), framework is **Testo** (not PHPUnit): `#[Test]`, `#[Covers]`,
`Testo\Assert` / `Testo\Expect`.

- **Unit** (`tests/Unit`) — no database, runs everywhere.
- **Acceptance** (`tests/Acceptance`) — runs the same scenarios against a matrix of SQL drivers
  (SQLite / Postgres / MySQL) via a Testo plugin (`tests/Acceptance/Testo`). The abstract scenarios
  live in `tests/Acceptance/Common`; one empty `#[Group('driver-<x>')]` subclass per driver in
  `tests/Acceptance/Driver/<Driver>` is what Testo discovers.

### Writing acceptance tests — IMPORTANT

Tables are created **once per driver** and are **never cleaned between tests** (unlike Cycle
ActiveRecord, we do NOT wrap each test in a rolled-back transaction — the inbox driver uses
`TransactionMode::Exclusive`, which throws if an outer transaction is already open, and the whole
point of the dedup test is a real top-level COMMIT).

Therefore **every test must use a distinct idempotency key** so its rows never collide with another
test's rows in the shared tables. Use `$this->key()` (a per-call unique key) from `DatabaseTestCase`
and reuse that one value within the test. For helper side-effect tables (e.g. `ledger`), tag rows
with the unique key and count only those (`WHERE note = $key`).

Read the connection from the base class: `$this->db()` / `$this->manager()`. If the target database
is unreachable the plugin marks the test **skipped** (never failed).

### Running

```
composer test:unit                 # no DB
composer test:sqlite               # acceptance on SQLite (in-memory, no service needed)
composer test:pgsql                # acceptance on Postgres  (needs docker-compose service)
composer test:mysql                # acceptance on MySQL
composer test:no-driver            # everything except driver-bound tests

docker compose -f tests/docker-compose.yml up -d     # Postgres :15432, MySQL :13306
```

Driver connection defaults live in `tests/Acceptance/Testo/DatabaseDriver.php` (overridable via
`DB_HOST` / `DB_PORT` / `DB_USER` / `DB_PASSWORD` / `DB_DATABASE`) and match the docker-compose file.

## Cross-dialect gotchas

- MySQL `rowCount()` (PDO default) returns *changed*, not *matched* rows — a no-op UPDATE returns 0.
  This is why the lease `renew()`/`complete()` CAS and the inbox affected-row dedup are dialect-sensitive
  and MUST be covered on MySQL, not only SQLite.
- The upsert `DO NOTHING` affected-row count relies on `cycle/database >= 2.21` (it fixed the MySQL
  row-alias bug that made `DO NOTHING` ambiguous and the Postgres quoted-`EXCLUDED` bug).
