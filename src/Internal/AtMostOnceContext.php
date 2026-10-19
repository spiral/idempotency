<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal;

use Spiral\Idempotency\IdempotencyContext;

/**
 * Context handed to the operation closure by the at-most-once (dedup-guard) drivers. The effect runs
 * fire-and-forget, outside any transaction the driver controls, so there is nothing transport-specific
 * to expose beyond the key — like {@see \Spiral\Idempotency\Internal\Lease\LeaseContext}. There is no
 * renewable lock, so {@see IdempotencyContext::renew()} is a no-op (via {@see NoRenewal}).
 *
 * @internal Created by the driver; users only ever see the {@see IdempotencyContext} contract.
 */
final readonly class AtMostOnceContext implements IdempotencyContext
{
    use NoRenewal;

    /**
     * @param non-empty-string $key
     */
    public function __construct(
        private string $key,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }
}
