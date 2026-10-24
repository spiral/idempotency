<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * A duplicate of an operation that already consumed its key, with no outcome of the first run to replay:
 * an AtMostOnce storage never re-runs, and either keeps no results or the first run has not stored one
 * (still running, crashed, or threw). Distinct from a replayed `null`, which is a stored outcome.
 *
 * @api
 */
final class OutcomeUnknownException extends IdempotencyException
{
    /**
     * @param non-empty-string $key
     * @param bool $final true when no outcome will ever be stored for the key (the result cache is off),
     *        so every later call answers the same; false when a later call may still find one
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
