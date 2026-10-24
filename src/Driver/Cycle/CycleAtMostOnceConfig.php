<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleAtMostOnceFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactory;

/**
 * Data-only config for an AtMostOnce dedup-guard over a Cycle DBAL connection.
 *
 * The dedup marker commits before the effect and is never removed, so the effect runs at most once and
 * a duplicate is *refused* rather than re-run (a repeat is therefore NOT safe to retry). This guards
 * the effect (≤ 1) but does not cache the answer by default: a duplicate throws
 * {@see \Spiral\Idempotency\Exception\OutcomeUnknownException}.
 *
 * Set {@see $cacheResult} to also store and replay the operation result — best-effort only: the marker
 * and the result are not committed atomically, so a crash between them loses the cache (not the dedup),
 * and that duplicate throws the same exception.
 * Atomic marker + result is the upgrade to {@see \Spiral\Idempotency\Driver\Cycle\CycleInboxConfig}.
 *
 * Security: with {@see $cacheResult} on, results are (de)serialized by the storage's
 * {@see \Spiral\Serializer\SerializerInterface}; the default {@see \Spiral\Serializer\Serializer\PhpSerializer}
 * restores them via `unserialize()` on replay, so the table is the trust boundary — bind a JSON
 * serializer if several services or roles write into it.
 *
 * @api
 */
final class CycleAtMostOnceConfig extends StorageConfig
{
    /**
     * @param string|null $connection DBAL database name (null = the default database)
     * @param non-empty-string $table dedup-guard table
     * @param bool $cacheResult store the operation result — `null` and an {@see \Spiral\Idempotency\Uncacheable}
     *        value included — and best-effort replay it on a duplicate (default off: a duplicate throws
     *        {@see \Spiral\Idempotency\Exception\OutcomeUnknownException})
     * @param int|null $retentionTtl dedup-marker retention, seconds. `null` (default) keeps markers
     *        forever (the "at most once" guarantee never weakens; GC skips this storage). A positive
     *        value lets {@see \Spiral\Idempotency\Driver\Cycle\CycleGarbageCollector} delete rows whose
     *        `create_time` is older than `now - retentionTtl`.
     *        IMPORTANT: enabling retention narrows the deduplication window — once a marker is swept, a
     *        delayed duplicate (late redelivery/replay) is no longer recognized and RE-EXECUTES, so the
     *        "at most once" promise only holds within the retention window. Choose a value safely longer
     *        than the maximum expected redelivery/replay delay.
     * @param Guarantee $guarantee declared guarantee (must be backable by the at-most-once driver)
     */
    public function __construct(
        public readonly ?string $connection = null,
        public readonly string $table = 'idempotency_at_most_once',
        public readonly bool $cacheResult = false,
        public readonly ?int $retentionTtl = null,
        public readonly Guarantee $guarantee = Guarantee::AtMostOnce,
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
        return CycleAtMostOnceFactory::class;
    }
}
