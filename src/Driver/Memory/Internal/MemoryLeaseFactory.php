<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Memory\Internal;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Memory\MemoryLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\StorageFactory;
use Spiral\Idempotency\StorageServices;

/**
 * Builds the lease engine ({@see DefaultLeaseManager} + {@see LeaseIdempotency}) over an
 * {@see InMemoryLeaseStorage} from a {@see MemoryLeaseConfig}. No connection to resolve — the
 * storage is the array this factory allocates, so each alias gets its own keyspace.
 *
 * @internal Resolved from {@see MemoryLeaseConfig::factory()} via the container; not part of the public API.
 */
final readonly class MemoryLeaseFactory implements StorageFactory
{
    public function create(StorageConfig $config, StorageServices $services): Idempotency
    {
        if (!$config instanceof MemoryLeaseConfig) {
            throw new MisconfigurationException(
                \sprintf('%s expects %s, got %s.', self::class, MemoryLeaseConfig::class, $config::class),
                'A storage config and its factory() must pair up: MemoryLeaseConfig → MemoryLeaseFactory, '
                . 'RedisLeaseConfig → RedisLeaseFactory. Check the factory() method of the config class.',
            );
        }

        /** @var Pipeline<\Spiral\Idempotency\Pipeline\ExecutionCall> $execution */
        $execution = new Pipeline(new ClassifierMiddleware($services->classifier));

        return new LeaseIdempotency(
            new DefaultLeaseManager(
                new InMemoryLeaseStorage($services->clock),
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
