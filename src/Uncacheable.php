<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Wraps a transient operation result (e.g. a 5xx HTTP response) that a resolution middleware asks not
 * to cache, signalling "safe to re-run". That holds for the lease only.
 *
 * The lease handler honours it: it releases the key (abort) and returns the unwrapped {@see $value}, so
 * a retry re-executes the operation. The storages that never re-run — the inbox and at-most-once — unwrap,
 * store and replay the value like any other: their record is already terminal (the inbox side-effect is
 * committed, the at-most-once marker precedes the effect), so a retry gets the same transient answer.
 *
 * @api
 */
final readonly class Uncacheable
{
    public function __construct(
        public mixed $value,
    ) {}
}
