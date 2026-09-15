<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Attribute;

use Spiral\Idempotency\FailurePolicy;

/**
 * Marks a handler as idempotent. The target carries a *semantic storage alias*; the
 * concrete driver and the declared guarantee live in config — infra never leaks into
 * business code.
 *
 * Placed on a method it covers that method; placed on a class it covers *every* method the transport
 * dispatches to on that class — including one inherited from an abstract base (a `JobHandler::handle()`
 * that forwards to the subclass), which is the only way to annotate a handler that never redeclares the
 * entry point. On a job handler that is the single entry point; on a controller it makes every action
 * idempotent under one storage alias.
 *
 * A method attribute wins over a class one, and the nearest class in the inheritance chain wins over
 * its parents.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Idempotent
{
    /**
     * Explicit opt-out value for {@see $scope}: share one global key space for the storage alias, the
     * pre-namespacing behaviour where uniqueness across endpoints is the client's responsibility.
     */
    public const SCOPE_GLOBAL = '';

    /**
     * @param non-empty-string $storage semantic alias from config (driver + guarantee live there)
     * @param string|null $key property-path to the key (dot-notation over the call arguments, resolved by
     *        {@see \Spiral\Idempotency\ArgumentKeyResolver}: scalar, Stringable or backed-enum leaves),
     *        or null to let a transport middleware supply the key
     * @param int<1, max>|null $lockTtl override the PROCESSING lock TTL in seconds; null = from config.
     *        Applies to the lease/AtLeastOnce driver only — the inbox/ExactlyOnce driver ignores it
     *        (its mutual exclusion is the row lock of the in-progress INSERT, not a time-bound lease).
     * @param int<1, max>|null $ttl override the retention TTL in seconds; null = from config
     * @param string|null $scope key-space namespace mixed into the key as `parentKey` so the same client
     *        `Idempotency-Key` on two different endpoints does not collide in a shared storage alias:
     *
     *        | value | behaviour |
     *        |---|---|
     *        | `null` (default) | auto: namespace by operation identity (`Class::method`) — the safe default |
     *        | `'some-name'` | explicit name — deliberately share one key space across several endpoints (e.g. an HTTP endpoint and a Queue job that are the same logical operation) |
     *        | {@see self::SCOPE_GLOBAL} (`''`) | opt out: one global key space for the alias, client owns uniqueness |
     *
     *        Applies to the transport (attribute/interceptor) path only — a direct
     *        {@see \Spiral\Idempotency\Idempotency::execute()} call takes the final key as given.
     * @param FailurePolicy|null $failurePolicy what happens to the key when the operation throws:
     *        {@see FailurePolicy::Cache} turns a Domain failure into a replayable negative outcome,
     *        {@see FailurePolicy::Release} frees the key on any failure so the next delivery re-runs the
     *        operation. `null` = this transport's default (queue: Release; HTTP/gRPC: Cache).
     *        Lease/AtLeastOnce storages only — the inbox and at-most-once drivers ignore it.
     */
    public function __construct(
        public string $storage,
        public ?string $key = null,
        public ?int $lockTtl = null,
        public ?int $ttl = null,
        public ?string $scope = null,
        public ?FailurePolicy $failurePolicy = null,
    ) {}
}
