<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Redis;

use Spiral\Core\Container;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\Internal\RedisAtMostOnceFactory;
use Spiral\Idempotency\Driver\Redis\Internal\RedisConnection;
use Spiral\Idempotency\Driver\Redis\RedisAtMostOnceConfig;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Tests\Unit\Stub\FakeRedisCommands;
use Spiral\Serializer\Serializer\JsonSerializer;
use Spiral\Serializer\Serializer\PhpSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * How the factory finds its connection and wires the config into the driver. Nothing here talks to a
 * server: predis connects lazily and the failure paths throw before any I/O.
 */
#[Test]
#[Covers(RedisAtMostOnceFactory::class)]
#[Covers(RedisConnection::class)]
final class RedisAtMostOnceFactoryTest
{
    public function wiresTheConfigIntoTheDriver(): void
    {
        $redis = new FakeRedisCommands();
        $container = new Container();
        $container->bindSingleton(RedisCommands::class, $redis);
        $config = new RedisAtMostOnceConfig(keyPrefix: 'p:', retentionTtl: 60, cacheResult: true);

        $driver = new RedisAtMostOnceFactory($container)->create($config, $this->services());
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'v';
        };

        Assert::instanceOf($driver, GuaranteeProvider::class);
        Assert::same($driver->guarantee(), Guarantee::AtMostOnce);
        Assert::same($driver->execute('k', $op), 'v');
        Assert::same($driver->execute('k', $op), 'v');
        Assert::same($calls, 1);
        Assert::same($redis->calls[0]['keys'], ['p:k']);
        Assert::same($redis->calls[0]['args'][1], '60');
    }

    public function theResultCacheUsesTheServicesSerializer(): void
    {
        $redis = new FakeRedisCommands();
        $container = new Container();
        $container->bindSingleton(RedisCommands::class, $redis);
        $services = new StorageServices(
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
            new JsonSerializer(),
        );

        new RedisAtMostOnceFactory($container)
            ->create(new RedisAtMostOnceConfig(cacheResult: true), $services)
            ->execute('k', static fn(): array => ['id' => 42]);

        Assert::same($redis->store['idempotency:amo:k']['value'], 'R:{"id":42}');
    }

    public function withoutTheResultCacheADuplicateGetsNull(): void
    {
        $container = new Container();
        $container->bindSingleton(RedisCommands::class, new FakeRedisCommands());

        $driver = new RedisAtMostOnceFactory($container)->create(new RedisAtMostOnceConfig(), $this->services());

        Assert::same($driver->execute('k', static fn(): string => 'v'), 'v');
        Assert::null($driver->execute('k', static fn(): string => 'v'));
    }

    public function fallsBackToAPredisClientBinding(): void
    {
        $container = new Container();
        $container->bindSingleton(\Predis\ClientInterface::class, new \Predis\Client());

        $driver = new RedisAtMostOnceFactory($container)->create(new RedisAtMostOnceConfig(), $this->services());

        Assert::instanceOf($driver, GuaranteeProvider::class);
    }

    public function failsFastWithoutAnyConnectionBinding(): void
    {
        Expect::exception(MisconfigurationException::class)->withMessage(
            RedisAtMostOnceConfig::class . ' needs a Redis connection, but the container binds neither '
            . RedisCommands::class . ' nor \Predis\ClientInterface.',
        );

        new RedisAtMostOnceFactory(new Container())->create(new RedisAtMostOnceConfig(), $this->services());
    }

    public function rejectsAForeignConfig(): void
    {
        $foreign = new class extends StorageConfig {
            public function guarantee(): Guarantee
            {
                return Guarantee::AtMostOnce;
            }

            public function factory(): string
            {
                return RedisAtMostOnceFactory::class;
            }
        };

        // A bound connection, so only the config guard can throw here.
        $container = new Container();
        $container->bindSingleton(RedisCommands::class, new FakeRedisCommands());

        Expect::exception(MisconfigurationException::class)->withMessageContaining('expects ' . RedisAtMostOnceConfig::class);

        new RedisAtMostOnceFactory($container)->create($foreign, $this->services());
    }

    public function theBootloaderRegistersTheAlias(): void
    {
        $registry = $this->registry(new RedisAtMostOnceConfig());

        Assert::true($registry->has('jobs'));
    }

    public function theBootloaderRejectsADeclaredGuaranteeTheDriverCannotBack(): never
    {
        Expect::exception(MisconfigurationException::class)->withMessageContaining('ExactlyOnce');

        $this->registry(new RedisAtMostOnceConfig(guarantee: Guarantee::ExactlyOnce));
    }

    private function registry(RedisAtMostOnceConfig $config): IdempotencyRegistry
    {
        $container = new Container();
        $container->bindSingleton(RedisCommands::class, new FakeRedisCommands());

        return new IdempotencyBootloader()->initRegistry(
            new IdempotencyConfig(['storages' => ['jobs' => $config]]),
            $container,
            $container,
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
        );
    }

    private function services(): StorageServices
    {
        return new StorageServices(
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
            new PhpSerializer(),
        );
    }
}
