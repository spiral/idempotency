# Failure handling, replay fidelity, and garbage collection

## Failure classification

An exception thrown by the operation is classified into one of three kinds — deterministic
outcome and "will a retry help" are independent axes. Bind `FailureClassifier` to
customize the mapping.

| Kind | Default mapping | Lease reaction |
|---|---|---|
| **Domain** | any other `\Exception` | Cached as a valid negative outcome, replayed on retry |
| **Infrastructure** | `\Error`, or `\Exception` implementing `Retryable` | Key released; the transport/client retries |
| **Bug** | only via explicit config: `DefaultFailureClassifier(bugExceptions: [...])` | Key released; no re-enqueue — report and fix |

## Failure policy (`Cache` vs `Release`)

Classification says what kind of failure happened; `FailurePolicy` says whether the key stays taken.

| Policy | Effect on a thrown failure |
|---|---|
| `Cache` | The table above applies: a Domain failure is cached and replayed |
| `Release` | Any failure aborts the lease and rethrows the original throwable unchanged; the next call re-runs the operation |

Defaults follow who owns the retry — queue: `Release` (the broker redelivers), HTTP and gRPC: `Cache`
(the client repeats the call and must get the same answer), direct `execute()`: `Cache`. Override per
operation: `#[Idempotent(..., failurePolicy: FailurePolicy::Release)]` or
`new ExecuteOptions(failurePolicy: ...)`.

Lease/AtLeastOnce storages only. The inbox and at-most-once drivers ignore the policy: an inbox failure
already rolls the dedup row back together with the side-effect, and a committed record of either driver
is terminal — releasing it would run the effect twice.

With `Release` there is no cached negative outcome, so nothing below about failure replay applies to
such an operation.

## Exact-type failure replay

A cached domain failure replays as `CachedDomainFailureException` carrying the original class name
and message. For an **exact-type** replay, implement `ReplayableFailure` on the domain
exception:

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

## Consistent HTTP status for a thrown domain failure

A *thrown* domain failure bypasses the response snapshot, so first attempt and replay are rendered
by different code — over HTTP that means a **different status code**, unless you do one of these
(best first):

1. **Return an error response** instead of throwing — snapshotted and replayed byte-identically,
   status included. Nothing to configure.
2. **Bind `Http\DomainFailureRenderer`** — keeps the throwing style:
   `HttpOutcomeMiddleware` renders Domain-classified throwables into a response *inside* the
   operation, so the snapshot (status included) is cached and the replay carries the
   `Idempotency-Replay` header too:

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

   Bind it in a bootloader (`DomainFailureRenderer::class => DeclineRenderer::class`).
   Only `FailureKind::Domain` failures reach the renderer; Infrastructure and Bug ones stay
   exceptions (key released, retry re-runs). The `cacheable` predicate still applies — a rendered
   5xx is not cached either. Rows written *before* the renderer was bound keep replaying as a throw.
3. **Map `CachedDomainFailureException::$originalClass`** in the application exception handler.

Over gRPC none of this is needed: a negative outcome *is* a status, and code + message + details is
a complete snapshot — thrown `GRPCException`s replay identically out of the box.

## Garbage collection

`Driver\Cycle\CycleGarbageCollector::collect()` sweeps expired rows and returns
`alias => rows deleted`:

- **lease** storages — always swept (rows whose `expire_time` has passed);
- **inbox / at-most-once** storages — only when their `retentionTtl` is set; the default `null`
  keeps records forever so the dedup guarantee never weakens.

**Enabling retention narrows the dedup window**: once a record is swept, a late duplicate (delayed
redelivery/replay) is no longer recognized and RE-EXECUTES. Choose a TTL safely longer than the
worst expected redelivery delay.

Scheduling the GC (cron, `spiral/scheduler`, a console command) is the application's policy — the
package does not run it by itself. The Redis lease needs no GC: server-side `EXPIRE` carries both
TTLs, an expired lease simply vanishes.

## Customization points

- **Middleware** — implement `ResolutionMiddleware` (transport phase: key extraction / outcome
  mapping) and list it under `transports.<name>`, or `ExecutionMiddleware` (domain phase around
  the operation).
- **Serializer** — cached results use `spiral/serializer` (`PhpSerializer` by default); bind your
  own `SerializerInterface` to switch, e.g. to JSON. The default `PhpSerializer` runs
  `unserialize()` on blobs read back from the tables — the table/keyspace is the trust boundary;
  with multiple writers, bind a JSON serializer and keep results JSON-safe.
- **Classifier** — bind `FailureClassifier`.
- **Key policy** — bind `KeyResolver` (normalization, hashing, hierarchy composition), or
  `ArgumentKeyResolver` (how the attribute's `key` dot-path is walked and which leaf types have a
  string form).
- **Schema** — ORM role names via `SchemaNaming`; table names live in the storage configs.
