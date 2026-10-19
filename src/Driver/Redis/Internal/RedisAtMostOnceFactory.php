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
