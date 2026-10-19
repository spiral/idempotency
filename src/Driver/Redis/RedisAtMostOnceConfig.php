<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\Internal\RedisAtMostOnceFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactory;

/**
 * Data-only config for an AtMostOnce dedup-guard over a Redis-compatible server (Valkey, Redis).
 *
 * The marker is one string key at `{keyPrefix}{key}`, claimed by an atomic `SET ... NX EX` *before* the
 * effect and never removed on any outcome, so the effect runs at most once and a duplicate is *refused*
 * rather than re-run (a repeat is therefore NOT safe to retry). A duplicate gets `null` by default.
 *
 * The TTL is the deduplication window, counted from the claim (not from completion): once the marker
 * expires, a duplicate is no longer recognized and RE-EXECUTES — even one arriving while the first
 * operation still runs. Size it above the longest operation plus the longest redelivery delay.
 * Server-side expiry is also the whole retention policy — no garbage collector involved.
 *
 * A marker lost before its TTL lets a duplicate re-run the effect too. Any `volatile-*`/`allkeys-*`
 * eviction policy may drop markers (`volatile-ttl` the short-lived ones first), so the server needs
 * `maxmemory-policy noeviction` — a dedicated instance if a shared one must evict — and AOF persistence,
 * since a restart without it loses them. A failover to an asynchronously replicated replica can still lose
 * the latest claims. Runs on Valkey and Redis 3.2+.
 *
 * Set {@see $cacheResult} to also store and replay the operation result — best-effort only: the result
 * is a second write after the effect, so a crash or a Redis error between the two loses the cache (not
 * the dedup). Atomic marker + result needs one transaction around the effect, i.e. the Cycle inbox
 * ({@see \Spiral\Idempotency\Driver\Cycle\CycleInboxConfig}).
 *
 * The connection comes from the container exactly as for {@see RedisLeaseConfig}: a {@see RedisCommands}
 * binding, or a `\Predis\ClientInterface` binding wrapped in {@see PredisCommands}.
 *
 * Security: with {@see $cacheResult} on, results are (de)serialized by the storage's
 * {@see \Spiral\Serializer\SerializerInterface}; the default {@see \Spiral\Serializer\Serializer\PhpSerializer}
 * restores them via `unserialize()` on replay, so the Redis keyspace is the trust boundary — bind a JSON
 * serializer if several services or roles write into it.
 *
 * @api
 */
final class RedisAtMostOnceConfig extends StorageConfig
{
    /**
     * @param non-empty-string $keyPrefix prepended to every Redis key (namespacing). On a server shared with a
     *        {@see RedisLeaseConfig}, neither prefix may start with the other: the lease keeps a hash under
     *        the key, this driver a string, so a shared key reads as taken or fails with `WRONGTYPE`
     * @param int<1, max> $retentionTtl dedup window, seconds: the marker's server-side TTL, counted from the
     *        claim. Choose a value safely longer than the longest operation plus the longest redelivery/replay
     *        delay; a value below 1 fails when the storage is built. `ttl:` on `#[Idempotent]` overrides it per
     *        operation, and below 1 throws before the effect.
     * @param bool $cacheResult store the operation result and best-effort replay it on a duplicate
     *        (default off: a duplicate gets `null`)
     * @param Guarantee $guarantee declared guarantee (must be backable by the at-most-once driver)
     */
    public function __construct(
        public readonly string $keyPrefix = 'idempotency-amo:',
        public readonly int $retentionTtl = 86400,
        public readonly bool $cacheResult = false,
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
        return RedisAtMostOnceFactory::class;
    }
}
