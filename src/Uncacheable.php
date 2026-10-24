<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Wraps a transient operation result (e.g. a 5xx HTTP response) that a resolution middleware asks not
 * to cache, signalling "safe to re-run".
 *
 * Only the lease honours it: it releases the key (abort) and returns the unwrapped {@see $value}, so a
 * retry re-executes the operation. The inbox and at-most-once storages never re-run — their record is
 * already terminal (the inbox side-effect is committed, the at-most-once marker precedes the effect) —
 * so they unwrap the value and store it like any other result.
 *
 * @api
 */
final readonly class Uncacheable
{
    public function __construct(
        public mixed $value,
    ) {}
}
