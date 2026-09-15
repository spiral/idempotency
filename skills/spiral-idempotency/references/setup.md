# Setup — spiral/idempotency

Full setup path: inspect the infrastructure, install, register bootloaders, write the config,
wire the interceptor, create the tables. Skip steps that are already done — the inspect script
in step 0 tells you which ones those are.

## 0. Inspect the infrastructure first

Which optional bootloaders, storage drivers, and transports apply depends on what the project
already has. Run the bundled script from the project root (read-only, prints a report):

```bash
php <this-skill-dir>/scripts/inspect-environment.php --root=.
```

It reports:

- installed packages relevant to idempotency (and whether `cycle/database` satisfies the
  `>= 2.21` requirement for the upsert dedup);
- which transports the project can use (HTTP / queue / gRPC / events) based on installed packages;
- which database engines are configured (`app/config/database.php`: Postgres / MySQL / SQLite),
  and whether docker-compose declares a `redis`/`valkey` service for the Redis lease;
- which idempotency bootloaders are already registered and whether
  `app/config/idempotency.php` already exists.

If the script cannot run, gather the same facts manually: read `composer.json` +
`vendor/composer/installed.php`, `app/config/database.php`, the Kernel's bootloader list, and
`docker-compose.yml`.

Decide from the report:

| Fact | Consequence |
|---|---|
| `cycle/database` present | `CycleLeaseConfig` / `CycleInboxConfig` / `CycleAtMostOnceConfig` are available |
| `cycle/database` < 2.21 | The `DO NOTHING` affected-row dedup is broken on MySQL/Postgres below that — tell the user an upgrade is required (their call to run it) |
| A Redis client is present (`predis/predis`, `ext-redis`, any other) or a Redis service exists | `RedisLeaseConfig` is available for AtLeastOnce (no GC needed); a non-predis client needs a `Driver\Redis\RedisCommands` adapter binding |
| Neither a Redis client nor `cycle/database` is available (or the alias only has to work in tests / local development) | `MemoryLeaseConfig` backs AtLeastOnce in a per-process array — no connection, no schema; dedup is lost across workers, so it is never a production answer |
| `spiral/queue` present | The queue transport applies (`QueueIdempotencyBootloader`) |
| `spiral/roadrunner-bridge` + `spiral/roadrunner-grpc` present | The gRPC transport applies (`GrpcIdempotencyBootloader`) |
| `spiral/events` present | The PSR-14 events transport applies (`EventsIdempotencyBootloader`) — no `transports.events` config section, no interceptor |
| `spiral/cycle-bridge` present | `CycleSchemaBootloader` can put the tables into the ORM schema (`cycle:sync`/`cycle:migrate`) |

## 1. Install

If step 0 shows `spiral/idempotency` is not installed yet (usually it already is):

```bash
composer require spiral/idempotency
```

That is the **only** package you may install on your own. Optional peers — `cycle/database`
(bundled SQL driver), `spiral/interceptors` (the attribute path), `spiral/queue` (queue
transport), `spiral/events` (PSR-14 listeners), `predis/predis` (Redis lease when the app has no Redis
client to adapt), `spiral/cycle-bridge` (tables in the ORM schema) —
are architecture decisions: if step 0 shows one is missing but needed, **ask the user for
approval before installing it**; never `composer require` them unprompted.

## 2. Bootloaders

```php
// app/src/Application/Kernel.php — defineBootloaders()
\Spiral\Idempotency\Bootloader\IdempotencyBootloader::class,
\Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader::class,
\Spiral\Idempotency\Bootloader\QueueIdempotencyBootloader::class,
\Spiral\Idempotency\Bootloader\GrpcIdempotencyBootloader::class,
\Spiral\Idempotency\Bootloader\EventsIdempotencyBootloader::class,
\Spiral\Idempotency\Bootloader\CycleSchemaBootloader::class,
```

`IdempotencyBootloader` is always required. The rest are opt-in: register only the transports the
project actually dispatches — each one binds a transport-flavored interceptor in its own
dispatcher scope (`http` / `queue` / `grpc`) — and `CycleSchemaBootloader` only when the tables
go through `cycle:sync`/`cycle:migrate` (step 5, rung 1).

`EventsIdempotencyBootloader` is the odd one out: it binds no interceptor and needs no
`transports.events` entry. It replaces the framework's `ListenerFactoryInterface` so every
`#[Idempotent]` listener method gets its own key, and declares `EventsBootloader` as a dependency to
guarantee it registers after it.

## 3. Config — `app/config/idempotency.php`

```php
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Http\HttpKeyMiddleware;
use Spiral\Idempotency\Http\HttpOutcomeMiddleware;

return [
    'default' => 'payments',

    // Resolution middleware per transport, outer → inner.
    'transports' => [
        'http' => [HttpOutcomeMiddleware::class, HttpKeyMiddleware::class],
        // 'queue' => [QueueRetryMiddleware::class, QueueKeyMiddleware::class],
        // 'grpc'  => [GrpcOutcomeMiddleware::class, GrpcKeyMiddleware::class],
    ],

    // Semantic aliases: handler code names an alias; driver + guarantee live here.
    'storages' => [
        'payments' => new CycleLeaseConfig(table: 'idempotency_lease', lockTtl: 30, retentionTtl: 3600),
        'orders'   => new CycleInboxConfig(table: 'idempotency_inbox'),
    ],
];
```

Rules for the `transports` section (uncomment/add the entries for the project):

- **Every transport whose bootloader is registered must have an entry** — `[]` is valid (the key
  then comes only from the attribute), but a *missing* one throws `MisconfigurationException` on
  the first `#[Idempotent]` call. The events transport is the exception: it runs no middleware
  stack, so it has no entry at all.
- **Middleware with constructor options** (a custom header name, `QueueKeyMiddleware`'s
  `fallbackToJobId: true` — see the queue section of `usage.md`) are bound as configured instances in
  a bootloader: the stack lists class names and resolves each through the container.
- **Order is outer → inner, outcome middleware outermost** (it marshals responses: replay headers,
  `Locked` → 409/ABORTED, missing key → 400), the key middleware inside it.

Storage config classes (all data-only; the driver is picked by the config class):

| Class | Guarantee | Key parameters (with defaults) |
|---|---|---|
| `Driver\Cycle\CycleLeaseConfig` | AtLeastOnce | `connection` (DBAL db name, null = default), `table` = `'idempotency'`, `lockTtl` = 30, `retentionTtl` = 86400, `heartbeatThreshold` = 0.5 |
| `Driver\Cycle\CycleInboxConfig` | ExactlyOnce | `connection`, `table` = `'inbox'`, `transactionMode` = `TransactionMode::Exclusive`, `flushMode`, `retentionTtl` = null (keep forever) |
| `Driver\Cycle\CycleAtMostOnceConfig` | AtMostOnce | `connection`, `table` = `'idempotency_at_most_once'`, `cacheResult` = false (duplicate gets `null`), `retentionTtl` = null |
| `Driver\Redis\RedisLeaseConfig` | AtLeastOnce | `keyPrefix` = `'idempotency:'`, `lockTtl` = 30, `retentionTtl` = 86400; needs a Redis connection in the container: a `Driver\Redis\RedisCommands` binding (three-command adapter over any Redis client) or `predis/predis` with a `\Predis\ClientInterface` binding; server-side TTL, no GC needed |
| `Driver\Memory\MemoryLeaseConfig` | AtLeastOnce | `lockTtl` = 30, `retentionTtl` = 86400; a per-process PHP array — no connection, no schema, no GC. Dedup holds only inside the worker that acquired the lease and dies with the process: offer it for tests and local development, never for production traffic spread over several workers |

The declared guarantee is verified against the driver capability at bootstrap — a mismatch fails
fast instead of silently weakening the promise.

## 4. Wire the interceptor — ALIAS, never the concrete class

Add one line to the existing domain-core interceptor list:

```php
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;

protected const INTERCEPTORS = [
    // ...existing interceptors (Cycle, Guard, ... — innermost after auth):
    IdempotencyInterceptor::class,
];
```

The alias is a forwarding proxy resolved per dispatcher scope (`http` / `queue` / `grpc`), so one
domain core serves every transport. Referencing the concrete `PipelineIdempotencyInterceptor` class
**fails** — it is deliberately unbound in the root container. Outside a transport scope the proxy
fails fast with `MisconfigurationException`.

For the queue, the interceptor goes on the **consume** side — either in `app/config/queue.php`
under `interceptors.consume`, or via the bootloader:

```php
$queue->addConsumeInterceptor(IdempotencyInterceptor::class);
```

Keep Spiral's default `RetryPolicyInterceptor` **outer** of it in the consume list (see the queue
section of `usage.md` for why).

## 5. Create the tables

Four ways, best first — go down the ladder only when the previous rung is unavailable or fails.
Table names always come from the storage configs; the Redis lease needs no schema at all.

1. **ORM schema injection** (needs `spiral/cycle-bridge`): with `CycleSchemaBootloader`
   registered, the tables join the ORM schema and the project's normal workflow creates them —
   `php app.php cycle:sync`, or generate a migration with `php app.php cycle:migrate` and run
   `php app.php migrate`. ORM role names are customizable via `SchemaNaming`.
2. **Migration artifact** (project uses `cycle/migrations`, but the ORM-schema injection is
   unavailable — e.g. no `spiral/cycle-bridge`, or `cycle:sync`/`cycle:migrate` is not part of
   the project's workflow): copy [`assets/create_idempotency_tables.php`](../assets/create_idempotency_tables.php)
   into the project's migrations directory and adapt it — the adaptation checklist is in the
   file's docblock (keep only configured tables, rename per config, filename format
   `<Ymd.His>_<N>_create_idempotency_tables.php`, namespace per existing migrations). Then run
   `php app.php migrate`.
3. **Plain SQL** (migrations are not `cycle/migrations` at all — Doctrine, Phinx, hand-run DDL):
   adapt [`assets/idempotency-tables.sql`](../assets/idempotency-tables.sql) (dialect notes
   inside) into the project's own mechanism.
4. **Programmatic declaration** (bootstrap/tests, no migration tooling):
   `CycleSchema::declare($db, $table)` / `declareInbox()` / `declareAtMostOnce()` create-or-sync
   the tables through the DBAL schema builder at runtime.

Both assets mirror `Driver\Cycle\CycleSchema` — the package's single source of truth for the
column layout; if they ever disagree, `CycleSchema` wins.

## 6. Verify

Re-run the inspect script — it should report every chosen bootloader registered and the config
present. Then list the declared storages to confirm the aliases and guarantees parse:

```bash
php <this-skill-dir>/scripts/list-storages.php --root=.
```
