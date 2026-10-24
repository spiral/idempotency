<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Single facade over both branches (lease/inbox). The driver behind a storage alias decides the
 * guarantee and how {@see $operation} is wrapped:
 *
 *  - lease/external driver (AtLeastOnce) wraps the operation in lease + CAS;
 *  - inbox driver (ExactlyOnce) runs it inside a DB transaction with the connection bound in
 *    the context.
 *
 * @api
 */
interface Idempotency
{
    /**
     * Run {@see $operation} idempotently under {@see $key}. On replay the cached result is returned
     * instead of executing the operation again.
     *
     * A replayed `null` is a stored outcome; an AtMostOnce duplicate with no outcome to replay throws
     * {@see Exception\OutcomeUnknownException} instead of re-running or inventing an answer.
     *
     * This programmatic path takes the {@see $key} as the final, authoritative key — it is NOT
     * namespaced automatically. Operation-identity namespacing (so the same client key on two endpoints
     * does not collide) is applied only on the attribute/interceptor path via
     * {@see \Spiral\Idempotency\Attribute\Idempotent::$scope}. A direct caller that needs the same
     * isolation composes it itself, e.g. via {@see KeyResolver::resolve()} (available as a
     * service) with an explicit parent key.
     *
     * @template T
     * @param non-empty-string $key
     * @param \Closure(IdempotencyContext): T $operation
     * @param ExecuteOptions|null $options per-call overrides (TTLs, failure policy); null fields fall
     *        back to the storage's configured defaults
     * @return T
     * @throws Exception\LockedException the key is held by another in-flight execution (lease)
     * @throws Exception\OutcomeUnknownException a duplicate with no outcome to replay (AtMostOnce)
     */
    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed;
}
