---
name: spiral-idempotency
description: "Set up and use the spiral/idempotency package — protect HTTP endpoints, queue jobs, and gRPC methods from duplicated side-effects on retries. Use when adding #[Idempotent] to a handler, configuring app/config/idempotency.php, choosing between AtLeastOnce / AtMostOnce / ExactlyOnce guarantees, wiring IdempotencyInterceptor into a domain core, handling Idempotency-Key headers, or debugging replayed responses / LockedException / MissingKeyException. Trigger on \"idempotent\", \"idempotency key\", \"deduplicate requests/jobs\", \"exactly once\", \"response replay\", \"transactional inbox\"."
---

# spiral/idempotency — setup and usage

The package guards operations against duplicated side-effects on retries in Spiral Framework
applications: one declarative `#[Idempotent]` attribute + one programmatic API, three guarantees
behind semantic storage aliases. For anything not covered by this skill, read
`vendor/spiral/idempotency/README.md` — it is the canonical, detailed reference.

## Pick the guarantee first

**Exactly-once delivery is impossible** in general; pick what the effect allows:

| Guarantee | Mechanism | Use when |
|---|---|---|
| **AtLeastOnce** | Lease: lock + fencing token, result cached and replayed | Default. External effects (payment gateway, email). Effect may repeat inside the crash window |
| **AtMostOnce** | Dedup-guard: marker commits *before* the effect, duplicate is **refused** | Losing the effect is safer than repeating it (fire-once jobs). Duplicate gets `null` |
| **ExactlyOnce** (effect) | Inbox: `INSERT ... ON CONFLICT DO NOTHING` + side-effect in **one DB transaction** | Only when the whole effect writes to the same database as the inbox record |

## Where to go

**Setting up** (installing the package, bootloaders, `app/config/idempotency.php`, interceptor
wiring, tables) → read [`references/setup.md`](references/setup.md). Its step 0 is discovering
what the project already has — run the bundled script from the project root before choosing
optional packages, bootloaders, and storage drivers:

```bash
php <this-skill-dir>/scripts/inspect-environment.php --root=.
```

**Using** (marking a handler idempotent over HTTP / queue / gRPC, the ExactlyOnce transactional
contract, the programmatic API, long-running operations) → read
[`references/usage.md`](references/usage.md). Before writing `#[Idempotent(storage: ...)]`,
list the aliases and guarantees the project actually declares:

```bash
php <this-skill-dir>/scripts/list-storages.php --root=.
```

**Failures and operations** (failure classification, `Cache`/`Release` failure policy, exact-type
failure replay, consistent HTTP status for thrown domain failures, garbage collection, customization
points) → read
[`references/failures-and-gc.md`](references/failures-and-gc.md).

Both scripts are read-only; `<this-skill-dir>` is the directory containing this SKILL.md.

## Pitfalls checklist

- Reference `IdempotencyInterceptor` (alias), never the concrete `PipelineIdempotencyInterceptor`.
- Every registered transport bootloader needs its `transports.<name>` entry in config — `[]` is
  valid, a missing one throws on the first `#[Idempotent]` call.
- Outcome middleware outer, key middleware inner in every `transports.<name>` list.
- Queue: `RetryPolicyInterceptor` must stay outer of the idempotency interceptor on consume.
- A handler whose entry point is inherited (an abstract `handle()`) carries `#[Idempotent]` on the
  **class**; it then covers every method the transport dispatches to on that class — on a
  controller, every action. A method attribute wins over a class one, a subclass over its base.
- Inbox = `TransactionMode::Exclusive`: no surrounding transaction around the handler.
- ExactlyOnce holds only for writes through `CycleContext` — any external effect degrades it to
  AtLeastOnce.
- Default `PhpSerializer` runs `unserialize()` on replay — the idempotency table/keyspace is the
  trust boundary. Multiple writers → bind a JSON `SerializerInterface` and keep results JSON-safe.
- `5xx` (HTTP) / transient statuses (gRPC) are not cached by design — retries re-run.
- A failing **queue** job releases its key by default (`FailurePolicy::Release`), an HTTP/gRPC failure
  is cached; set `failurePolicy:` on `#[Idempotent]` when the operation needs the other one.
- Setting `retentionTtl` on inbox/at-most-once narrows the dedup window — swept records mean a
  late duplicate re-executes.
- The upsert dedup relies on `cycle/database >= 2.21` (MySQL/Postgres `DO NOTHING` fixes).
