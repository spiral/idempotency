<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\PredisCommands;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Driver\Redis\RedisLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\StorageFactory;
use Spiral\Idempotency\StorageServices;

/**
 * Builds the lease engine ({@see DefaultLeaseManager} + {@see LeaseIdempotency}) over a
 * {@see RedisLeaseStorage} from a {@see RedisLeaseConfig}.
 *
 * The Redis connection comes from the container (see {@see RedisConnection}): a {@see RedisCommands}
 * binding wins; without one a `\Predis\ClientInterface` binding is wrapped in {@see PredisCommands}.
 *
 * @internal Resolved from {@see RedisLeaseConfig::factory()} via the container; not part of the public API.
 */
final readonly class RedisLeaseFactory implements StorageFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function create(StorageConfig $config, StorageServices $services): Idempotency
    {
        if (!$config instanceof RedisLeaseConfig) {
            throw new MisconfigurationException(
                \sprintf('%s expects %s, got %s.', self::class, RedisLeaseConfig::class, $config::class),
                'A storage config and its factory() must pair up: RedisLeaseConfig → RedisLeaseFactory, '
                . 'CycleLeaseConfig → CycleLeaseFactory. Check the factory() method of the config class.',
            );
        }

        /** @var Pipeline<\Spiral\Idempotency\Pipeline\ExecutionCall> $execution */
        $execution = new Pipeline(new ClassifierMiddleware($services->classifier));

        return new LeaseIdempotency(
            new DefaultLeaseManager(
                new RedisLeaseStorage(
                    RedisConnection::resolve($this->container, RedisLeaseConfig::class),
                    $services->clock,
                    $config->keyPrefix,
                ),
                $services->clock,
                $services->tokens,
            ),
            $execution,
            $config->lockTtl,
            $config->retentionTtl,
            $services->serializer,
            $services->classifier,
            logger: $services->logger,
        );
    }
}
