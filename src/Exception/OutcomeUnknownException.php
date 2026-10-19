<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * A duplicate of an operation that already consumed its key, while no outcome of that first run is
 * available to replay: an AtMostOnce storage never re-runs, and has nothing stored when its result cache
 * is off or the first run never reached the result write (a crash, a throw).
 *
 * Distinct from a replayed `null`, which is a real stored outcome. The transport middleware maps it to
 * `409 Conflict` without `Retry-After` (HTTP) / `ABORTED` without a retry hint (gRPC) — retrying the same
 * key never yields a different answer — while the queue ACKs the job and an event listener is skipped.
 *
 * @api
 */
final class OutcomeUnknownException extends IdempotencyException
{
    /**
     * @param non-empty-string $key
     */
    public function __construct(
        public readonly string $key,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Key "%s" was already consumed and no outcome is available to replay.', $key),
            0,
            $previous,
        );
    }
}
