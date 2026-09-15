<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Memory;

use Spiral\Core\Container;
use Spiral\Idempotency\Driver\Memory\Internal\MemoryLeaseFactory;
use Spiral\Idempotency\Driver\Memory\MemoryLeaseConfig;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\StorageFactory;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Serializer\Serializer\PhpSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MemoryLeaseFactory::class)]
final class MemoryLeaseFactoryTest
{
    public function resolvesThroughTheRegistryAndDedupsWithinTheProcess(): void
    {
        $registry = new IdempotencyRegistry();
        $config = new MemoryLeaseConfig(60, 3600);
        $registry->register('x', $this->build($config), $config->guarantee());

        $calls = 0;
        $operation = static function (IdempotencyContext $context) use (&$calls): string {
            ++$calls;
            return 'charged';
        };

        Assert::same($registry->get('x')->execute('order-1', $operation), 'charged');
        Assert::same($registry->get('x')->execute('order-1', $operation), 'charged');
        Assert::same($calls, 1);
    }

    public function eachAliasGetsItsOwnKeyspace(): void
    {
        $calls = 0;
        $operation = static function (IdempotencyContext $context) use (&$calls): int {
            return ++$calls;
        };

        $first = $this->build(new MemoryLeaseConfig());
        $second = $this->build(new MemoryLeaseConfig());

        Assert::same($first->execute('shared-key', $operation), 1);
        Assert::same($second->execute('shared-key', $operation), 2);
    }

    public function theCachedResultExpiresWithTheRetentionTtl(): void
    {
        $clock = new MutableClock();
        $idempotency = $this->build(new MemoryLeaseConfig(retentionTtl: 60), $clock);

        $calls = 0;
        $operation = static function (IdempotencyContext $context) use (&$calls): int {
            return ++$calls;
        };

        Assert::same($idempotency->execute('order-2', $operation), 1);
        $clock->advance(61);
        Assert::same($idempotency->execute('order-2', $operation), 2);
    }

    /**
     * Resolution goes through the container the way the bootloader does it, so a constructor that
     * cannot be autowired would fail here.
     */
    public function isResolvableFromTheContainerByItsConfig(): void
    {
        $factory = new Container()->make((new MemoryLeaseConfig())->factory());

        Assert::instanceOf($factory, MemoryLeaseFactory::class);
    }

    private function build(MemoryLeaseConfig $config, ?MutableClock $clock = null): Idempotency
    {
        $factory = new MemoryLeaseFactory();
        \assert($factory instanceof StorageFactory);

        return $factory->create($config, new StorageServices(
            $clock ?? new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
            new PhpSerializer(),
        ));
    }
}
