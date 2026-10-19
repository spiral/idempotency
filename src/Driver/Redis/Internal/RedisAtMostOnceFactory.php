<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\RedisAtMostOnceConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\StorageFactory;
use Spiral\Idempotency\StorageServices;

/**
 * Builds a {@see RedisAtMostOnceDriver} from a {@see RedisAtMostOnceConfig}, over the connection
 * {@see RedisConnection} finds in the container.
 *
 * @internal Resolved from {@see RedisAtMostOnceConfig::factory()} via the container; not part of the public API.
 */
final readonly class RedisAtMostOnceFactory implements StorageFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function create(StorageConfig $config, StorageServices $services): Idempotency
    {
        if (!$config instanceof RedisAtMostOnceConfig) {
            throw new MisconfigurationException(
                \sprintf('%s expects %s, got %s.', self::class, RedisAtMostOnceConfig::class, $config::class),
                'A storage config and its factory() must pair up: RedisAtMostOnceConfig → '
                . 'RedisAtMostOnceFactory. Check the factory() method of the config class.',
            );
        }

        // `int<1, max>` is a docblock promise only; a config built from env or an array can break it.
        /** @var int $retentionTtl */
        $retentionTtl = $config->retentionTtl;
        if ($retentionTtl < 1) {
            // The TTL is the whole dedup window: clamping it to one second would quietly turn the guard off.
            throw new MisconfigurationException(
                \sprintf('%s::$retentionTtl must be at least 1 second, got %d.', RedisAtMostOnceConfig::class, $retentionTtl),
                'Set retentionTtl to a dedup window longer than the longest operation plus the redelivery delay.',
            );
        }

        return new RedisAtMostOnceDriver(
            RedisConnection::resolve($this->container, RedisAtMostOnceConfig::class),
            $services->tokens,
            $config->keyPrefix,
            $config->retentionTtl,
            // The serializer is only ever touched when the result cache is on.
            $config->cacheResult ? $services->serializer : null,
            $config->cacheResult,
            $services->logger,
        );
    }
}
