<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * A duplicate of an operation that already consumed its key, while no outcome of that first run is
 * stored to replay. An AtMostOnce storage never re-runs, so it has nothing to answer with when its result
 * cache is off, or when the first run has not stored a result: it may still be running, or it crashed
 * or threw before the result write.
 *
 * Distinct from a replayed `null`, which is a real stored outcome. Without the result cache no outcome is
 * ever stored for the key ({@see $final}); with it, a later retry may find one and replay it. The
 * transport middleware maps it to `409 Conflict` without `Retry-After` (HTTP) / `ABORTED` without a retry
 * hint (gRPC) — there is no telling when, or whether, an outcome appears — while the queue ACKs the job
 * and an event listener is skipped.
 *
 * @api
 */
final class OutcomeUnknownException extends IdempotencyException
{
    /**
     * @param non-empty-string $key
     * @param bool $final no outcome will ever be stored for the key (the storage keeps none: an AtMostOnce
     *        result cache that is off), so every later call answers the same; false when a later call may
     *        still find one (the cache is on and the first run may not have written its result yet)
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $final,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Key "%s" was already consumed and no outcome is stored to replay.', $key),
            0,
            $previous,
        );
    }
}
