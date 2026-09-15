<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * What a lease does with the key when the operation throws. Orthogonal to
 * {@see Pipeline\FailureKind}: the kind describes the failure, the policy decides whether the
 * operation is allowed to run again under the same key.
 *
 * Only the lease/AtLeastOnce branch can honour it — a committed inbox/at-most-once record is
 * terminal, so both drivers ignore the policy (see their `execute()`).
 *
 * @api
 */
enum FailurePolicy
{
    /**
     * Let the failure kind pick the terminal transition: a Domain failure is cached and replayed,
     * Infrastructure releases the key, Bug marks the record errored.
     */
    case Cache;

    /**
     * Release the key on ANY failure and rethrow the original throwable: the next call re-runs the
     * operation. The transport (or the caller) owns the retry decision, so the storage must not turn a
     * failure into a permanent negative outcome.
     */
    case Release;
}
