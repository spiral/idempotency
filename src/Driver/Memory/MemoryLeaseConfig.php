<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Memory;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Memory\Internal\MemoryLeaseFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactory;

/**
 * Data-only config for an AtLeastOnce lease kept in a per-process PHP array.
 *
 * Dedup holds **only inside the worker that acquired the lease**: nothing is shared between
 * processes, and the whole state dies with the process. That makes it a fit for tests and local
 * development — and for a single-worker setup where the alias must resolve even though no Redis or
 * database is reachable — never for production traffic spread over several workers.
 *
 * Records carry a TTL like every other backend: an expired one is treated as absent, so the lock is
 * released on time even without a sweeper. Nothing needs the garbage collector here; the array is
 * pruned on read.
 *
 * @api
 */
final class MemoryLeaseConfig extends StorageConfig
{
    /**
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds
     * @param int<1, max> $retentionTtl COMPLETED retention TTL, seconds
     * @param Guarantee $guarantee declared guarantee (must be backable by the lease driver)
     */
    public function __construct(
        public readonly int $lockTtl = 30,
        public readonly int $retentionTtl = 86400,
        public readonly Guarantee $guarantee = Guarantee::AtLeastOnce,
    ) {}

    public function guarantee(): Guarantee
    {
        return $this->guarantee;
    }

    /**
     * @return class-string<StorageFactory>
     */
    public function factory(): string
    {
        return MemoryLeaseFactory::class;
    }
}
