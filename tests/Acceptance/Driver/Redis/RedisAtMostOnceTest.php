<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Driver\Redis;

use Spiral\Idempotency\Driver\Redis\Internal\RedisAtMostOnceDriver;
use Spiral\Idempotency\Driver\Redis\PredisCommands;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Filter\Group;
use Testo\Test;

/**
 * At-most-once dedup-guard over a real Redis/Valkey server — the Lua scripts are only proven here, the
 * unit test runs them against a fake. Same harness rules as {@see RedisLeaseStorageTest}: no SQL
 * plugin, a skip (never a failure) without a server, a process-unique key per test, and real sleeps
 * for expiry because server-side TTL is wall-clock.
 */
#[Test]
#[Covers(RedisAtMostOnceDriver::class)]
#[Group('driver', 'driver-redis')]
final class RedisAtMostOnceTest
{
    private const string PREFIX = 'idempotency-test:amo:';

    private static int $sequence = 0;

    private ?\Predis\Client $client = null;

    public function runsTheEffectOnceAndRefusesTheDuplicate(): void
    {
        $key = $this->key();
        $driver = $this->driver();
        $calls = 0;
        $op = static function (IdempotencyContext $c) use (&$calls): string {
            ++$calls;
            return 'v:' . $c->getKey();
        };

        Assert::same($driver->execute($key, $op), 'v:' . $key);
        Assert::null($driver->execute($key, $op));
        Assert::same($calls, 1);
    }

    public function theMarkerCarriesTheRetentionTtl(): void
    {
        $key = $this->key();
        $this->driver(retentionTtl: 120)->execute($key, static fn(): string => 'v');

        $ttl = $this->client()->ttl(self::PREFIX . $key);
        Assert::true($ttl > 100 && $ttl <= 120, "TTL is $ttl");
    }

    public function aThrowingEffectIsNotReRunEvenUnderRelease(): void
    {
        $key = $this->key();
        $driver = $this->driver();
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Release);
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            throw new \RuntimeException('boom');
        };

        try {
            $driver->execute($key, $op, $options);
            Assert::fail('the operation must propagate its RuntimeException');
        } catch (\RuntimeException) {
        }

        Assert::null($driver->execute($key, $op, $options));
        Assert::same($calls, 1);
    }

    public function theResultCacheReplaysAndKeepsTheTtl(): void
    {
        $key = $this->key();
        $driver = $this->driver(retentionTtl: 120, cacheResult: true);
        $calls = 0;
        $op = static function () use (&$calls): array {
            ++$calls;
            return ['id' => 42];
        };

        Assert::same($driver->execute($key, $op), ['id' => 42]);
        Assert::same($driver->execute($key, $op), ['id' => 42]);
        Assert::same($calls, 1);

        $ttl = $this->client()->ttl(self::PREFIX . $key);
        Assert::true($ttl > 100 && $ttl <= 120, "TTL is $ttl");
    }

    public function aDuplicateDuringTheEffectIsRefusedWithNull(): void
    {
        $key = $this->key();
        $driver = $this->driver(cacheResult: true);
        $inner = 'unset';

        $driver->execute($key, static function () use ($driver, $key, &$inner): string {
            $inner = $driver->execute($key, static fn(): string => 'never');
            return 'v';
        });

        Assert::null($inner);
    }

    public function theResultDoesNotOverwriteAMarkerClaimedByAnotherCaller(): void
    {
        $key = $this->key();
        $driver = $this->driver(cacheResult: true);
        $client = $this->client();

        $driver->execute($key, static function () use ($client, $key): string {
            $client->set(self::PREFIX . $key, 'M:other', 'EX', 60);
            return 'v';
        });

        Assert::same($client->get(self::PREFIX . $key), 'M:other');
    }

    public function aLateDuplicateRunsAgainOnceTheWindowLapses(): void
    {
        $key = $this->key();
        $driver = $this->driver(retentionTtl: 1);
        $calls = 0;
        $op = static function () use (&$calls): int {
            return ++$calls;
        };

        Assert::same($driver->execute($key, $op), 1);
        \usleep(1_200_000);
        Assert::same($driver->execute($key, $op), 2);
    }

    /**
     * @param int<1, max> $retentionTtl
     */
    private function driver(int $retentionTtl = 60, bool $cacheResult = false): RedisAtMostOnceDriver
    {
        return new RedisAtMostOnceDriver(
            new PredisCommands($this->client()),
            new RandomTokenFactory(),
            self::PREFIX,
            $retentionTtl,
            cacheResult: $cacheResult,
        );
    }

    /**
     * @return non-empty-string
     */
    private function key(): string
    {
        return 'k-' . (++self::$sequence) . '-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
    }

    private function client(): \Predis\Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 16379);

        $client = new \Predis\Client(['host' => $host, 'port' => $port]);
        try {
            $client->ping();
        } catch (\Throwable $e) {
            throw new SkipTest(\sprintf('Redis/Valkey not available at %s:%d (%s).', $host, $port, $e->getMessage()));
        }

        return $this->client = $client;
    }
}
