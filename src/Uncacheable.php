<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Wraps an operation result that must NOT be cached, signalling "safe to re-run". A resolution
 * middleware returns it for a transient outcome (e.g. a 5xx HTTP response).
 *
 * The lease handler honours it: it releases the key (abort) and returns the unwrapped {@see $value}, so
 * a retry re-executes the operation. The inbox handler unwraps and commits normally — its side-effect is
 * already transactionally fixed and cannot be safely re-run, so "re-run" is not an option there. The
 * at-most-once handler cannot re-run either (its marker stays), but it never stores the value, even with
 * the result cache on: a duplicate gets `null`.
 *
 * @api
 */
final readonly class Uncacheable
{
    public function __construct(
        public mixed $value,
    ) {}
}
