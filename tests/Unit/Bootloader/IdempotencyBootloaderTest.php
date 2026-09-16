<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Bootloader;

use Spiral\Core\Container;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Driver\Memory\MemoryLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(IdempotencyBootloader::class)]
final class IdempotencyBootloaderTest
{
    public function theDefaultAliasReachesTheRegistry(): void
    {
        $registry = $this->registry(['default' => 'orders', 'storages' => ['orders' => new MemoryLeaseConfig()]]);

        Assert::same($registry->resolve(null), $registry->get('orders'));
    }

    public function anUnknownDefaultAliasFailsOnBoot(): never
    {
        Expect::exception(MisconfigurationException::class)->withMessageContaining('typo');

        $this->registry(['default' => 'typo', 'storages' => ['orders' => new MemoryLeaseConfig()]]);
    }

    public function anAbsentDefaultIsNotAMisconfiguration(): void
    {
        $registry = $this->registry(['storages' => ['orders' => new MemoryLeaseConfig()]]);

        Assert::true($registry->has('orders'));
    }

    private function registry(array $config): IdempotencyRegistry
    {
        $container = new Container();

        return new IdempotencyBootloader()->initRegistry(
            new IdempotencyConfig($config),
            $container,
            $container,
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
        );
    }
}
