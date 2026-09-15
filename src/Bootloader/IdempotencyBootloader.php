<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\FactoryInterface;
use Spiral\Idempotency\ArgumentKeyResolver;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\StorageFactory;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Internal\SystemClock;
use Spiral\Idempotency\KeyResolver;
use Spiral\Idempotency\Lease\TokenFactory;
use Spiral\Idempotency\Pipeline\FailureClassifier;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * Wires the library into a Spiral application: binds the public contracts to their internal
 * implementations and assembles the {@see IdempotencyRegistry} from {@see IdempotencyConfig}. Because
 * the bindings live here, every concrete class stays {@see \Spiral\Idempotency\Internal} (or a
 * driver's own Internal namespace) — consumers depend only on the interfaces.
 *
 * Requires the application to provide {@see DatabaseProviderInterface} (cycle/database bootloader).
 *
 * Security: absent an application-provided {@see SerializerInterface}, cached results are stored with
 * {@see PhpSerializer} and restored via `unserialize()` on replay — so any process that writes a row is
 * injecting an object graph into every process that replays it. The storage table is the trust boundary.
 * If more than one service or role writes into it, bind a JSON serializer ({@see SerializerInterface})
 * and return JSON-safe results.
 *
 * @api
 */
final class IdempotencyBootloader extends Bootloader
{
    public function defineSingletons(): array
    {
        return [
            ClockInterface::class => SystemClock::class,
            KeyResolver::class => DefaultKeyResolver::class,
            ArgumentKeyResolver::class => DefaultArgumentKeyResolver::class,
            TokenFactory::class => RandomTokenFactory::class,
            FailureClassifier::class => DefaultFailureClassifier::class,
            IdempotencyRegistry::class => $this->initRegistry(...),
        ];
    }

    /**
     * Build the registry from config: each {@see \Spiral\Idempotency\Config\StorageConfig} DTO assembles
     * its own driver, and the registry verifies fail-fast that the driver can back the declared guarantee.
     *
     * Storage factories are resolved through the container, so a driver-specific factory injects its own
     * backend (a {@see \Cycle\Database\DatabaseProviderInterface}, a Redis client, ...) — keeping the core
     * free of any single backend.
     */
    public function initRegistry(
        IdempotencyConfig $config,
        ContainerInterface $container,
        FactoryInterface $factory,
        ClockInterface $clock,
        TokenFactory $tokens,
        FailureClassifier $classifier,
    ): IdempotencyRegistry {
        // The lease/inbox drivers cache results by serialising them. The default {@see PhpSerializer}
        // round-trips through unserialize() on replay: writing a row into the table is object-injection
        // into every process that replays it, so the table is the trust boundary. If several services or
        // roles write into it, bind a JSON serializer (SerializerInterface) and return JSON-safe results.
        // has() rather than an unconditional bind: an application's spiral/serializer component may already
        // provide SerializerInterface, and we defer to it instead of clobbering it.
        $serializer = $container->has(SerializerInterface::class)
            ? $container->get(SerializerInterface::class)
            : new PhpSerializer();

        $services = new StorageServices(
            $clock,
            $tokens,
            $classifier,
            $serializer,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );

        $registry = new IdempotencyRegistry();
        foreach ($config->getStorages() as $alias => $storage) {
            $storageFactory = $factory->make($storage->factory());
            \assert($storageFactory instanceof StorageFactory);
            $registry->register($alias, $storageFactory->create($storage, $services), $storage->guarantee());
        }

        return $registry;
    }
}
