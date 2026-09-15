<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Memory;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Memory\Internal\MemoryLeaseFactory;
use Spiral\Idempotency\Driver\Memory\MemoryLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Serializer\Serializer\PhpSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(MemoryLeaseConfig::class)]
final class MemoryLeaseConfigTest
{
    public function guaranteeDefaultsToAtLeastOnce(): void
    {
        Assert::same((new MemoryLeaseConfig())->guarantee(), Guarantee::AtLeastOnce);
    }

    public function guaranteeReflectsTheConstructedValue(): void
    {
        Assert::same(
            (new MemoryLeaseConfig(guarantee: Guarantee::ExactlyOnce))->guarantee(),
            Guarantee::ExactlyOnce,
        );
    }

    public function factoryNamesTheMemoryLeaseFactory(): void
    {
        Assert::same((new MemoryLeaseConfig())->factory(), MemoryLeaseFactory::class);
    }

    public function factoryRejectsAForeignConfig(): void
    {
        $factory = new MemoryLeaseFactory();
        $foreign = new class extends StorageConfig {
            public function guarantee(): Guarantee
            {
                return Guarantee::AtLeastOnce;
            }

            public function factory(): string
            {
                return MemoryLeaseFactory::class;
            }
        };

        Expect::exception(MisconfigurationException::class)->withMessageContaining('MemoryLeaseConfig');

        $factory->create($foreign, new StorageServices(
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
            new PhpSerializer(),
        ));
    }
}
