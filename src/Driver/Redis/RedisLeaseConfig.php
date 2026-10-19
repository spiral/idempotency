<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\Internal\RedisLeaseFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactory;

/**
 * Data-only config for an AtLeastOnce lease over a Redis/Valkey server.
 *
 * The lease record is one Redis HASH per key at `{keyPrefix}{key}`; server-side EXPIRE carries the
 * lock/retention TTL, so an expired lease simply vanishes (no explicit takeover branch, unlike the SQL
 * driver). Only the lease (AtLeastOnce) branch is offered here — the inbox (ExactlyOnce) needs a single
 * DB transaction over the side-effect and stays on the Cycle driver. Needs Redis 4.0+ (multi-field `HSET`)
 * or Valkey.
 *
 * No connection parameters live here: {@see RedisLeaseFactory} takes the connection from the container —
 * a {@see RedisCommands} binding (an adapter over any Redis client), or a `\Predis\ClientInterface`
 * binding wrapped in {@see PredisCommands} — mirroring how {@see \Spiral\Idempotency\Driver\Cycle\Internal\CycleLeaseFactory}
 * injects the DBAL provider.
 *
 * Security: cached results are (de)serialized by the storage's {@see \Spiral\Serializer\SerializerInterface}.
 * The default {@see \Spiral\Serializer\Serializer\PhpSerializer} restores them via `unserialize()` on replay,
 * so the Redis keyspace is the trust boundary. If several services or roles write into it, bind a JSON
 * serializer and return JSON-safe results (see {@see \Spiral\Idempotency\Bootloader\IdempotencyBootloader}).
 *
 * @api
 */
final class RedisLeaseConfig extends StorageConfig
{
    /**
     * @param non-empty-string $keyPrefix prepended to every Redis key (namespacing)
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds
     * @param int<1, max> $retentionTtl COMPLETED retention TTL, seconds
     * @param Guarantee $guarantee declared guarantee (must be backable by the lease driver)
     */
    public function __construct(
        public readonly string $keyPrefix = 'idempotency:',
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
        return RedisLeaseFactory::class;
    }
}
