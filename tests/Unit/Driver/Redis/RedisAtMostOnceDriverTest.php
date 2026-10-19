<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Redis;

use Psr\Log\AbstractLogger;
use Spiral\Idempotency\Driver\Redis\Internal\RedisAtMostOnceDriver;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Lease\TokenFactory;
use Spiral\Idempotency\Tests\Unit\Stub\FakeRedisCommands;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\JsonSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The at-most-once contract over {@see FakeRedisCommands}; the real server is exercised by
 * {@see \Spiral\Idempotency\Tests\Acceptance\Driver\Redis\RedisAtMostOnceTest}.
 */
#[Test]
#[Covers(RedisAtMostOnceDriver::class)]
final class RedisAtMostOnceDriverTest
{
    public function advertisesAtMostOnce(): void
    {
        Assert::same($this->driver(new FakeRedisCommands())->guarantee(), Guarantee::AtMostOnce);
    }

    public function runsTheEffectOnceAndRefusesTheDuplicateWithNull(): void
    {
        $driver = $this->driver(new FakeRedisCommands());
        $calls = 0;
        $op = static function (IdempotencyContext $c) use (&$calls): string {
            ++$calls;
            return 'v:' . $c->getKey();
        };

        Assert::same($driver->execute('k', $op), 'v:k');
        Assert::null($driver->execute('k', $op));
        Assert::same($calls, 1);
    }

    public function claimsThePrefixedKeyWithATokenMarkerAndTheRetentionTtl(): void
    {
        $redis = new FakeRedisCommands();
        $this->driver($redis, retentionTtl: 600)->execute('k', static fn(): string => 'v');

        Assert::same($redis->calls[0]['keys'], ['amo:k']);
        Assert::same($redis->calls[0]['args'], ['M:tok-1', '600', '0']);
        Assert::same($redis->store['amo:k']['ttlMs'], 600_000);
    }

    public function theOperationTtlOverridesTheRetentionTtl(): void
    {
        $redis = new FakeRedisCommands();
        $this->driver($redis, retentionTtl: 600)->execute('k', static fn(): string => 'v', new ExecuteOptions(ttl: 5));

        Assert::same($redis->calls[0]['args'][1], '5');
    }

    #[DataSet([0], 'zero')]
    #[DataSet([-1], 'negative')]
    public function aNonPositiveOperationTtlThrowsBeforeTheEffect(int $ttl): void
    {
        $redis = new FakeRedisCommands();
        $calls = 0;

        try {
            $this->driver($redis)->execute('k', static function () use (&$calls): string {
                ++$calls;
                return 'v';
            }, new ExecuteOptions(ttl: $ttl));
            Assert::fail('a non-positive operation ttl must throw');
        } catch (MisconfigurationException $e) {
            Assert::string((string) $e->getSolution())->contains('retentionTtl');
        }

        Assert::same($calls, 0);
        Assert::same($redis->calls, []);
    }

    public function aThrowingEffectIsNotReRun(): void
    {
        $driver = $this->driver(new FakeRedisCommands());
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            throw new \RuntimeException('boom');
        };

        try {
            $driver->execute('k', $op);
            Assert::fail('the operation must propagate its RuntimeException');
        } catch (\RuntimeException) {
        }

        Assert::null($driver->execute('k', $op));
        Assert::same($calls, 1);
    }

    public function theReleaseFailurePolicyIsIgnored(): void
    {
        $redis = new FakeRedisCommands();
        $driver = $this->driver($redis);
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Release);
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            throw new \RuntimeException('boom');
        };

        try {
            $driver->execute('k', $op, $options);
            Assert::fail('the operation must propagate its RuntimeException');
        } catch (\RuntimeException) {
        }

        Assert::true(isset($redis->store['amo:k']));
        Assert::null($driver->execute('k', $op, $options));
        Assert::same($calls, 1);
    }

    public function anExpiredMarkerLetsALateDuplicateRunAgain(): void
    {
        // The TTL is the dedup window: past it, a duplicate is no longer recognized.
        $redis = new FakeRedisCommands();
        $driver = $this->driver($redis);
        $calls = 0;
        $op = static function () use (&$calls): int {
            return ++$calls;
        };

        Assert::same($driver->execute('k', $op), 1);
        $redis->expire('amo:k');
        Assert::same($driver->execute('k', $op), 2);
    }

    public function withoutTheResultCacheNothingIsWrittenAfterTheEffect(): void
    {
        $redis = new FakeRedisCommands();
        $this->driver($redis)->execute('k', static fn(): string => 'v');

        Assert::count($redis->calls, 1);
        Assert::same($redis->store['amo:k']['value'], 'M:tok-1');
    }

    public function theResultCacheReplaysTheResultToADuplicate(): void
    {
        $redis = new FakeRedisCommands();
        $driver = $this->driver($redis, cacheResult: true);
        $calls = 0;
        $op = static function () use (&$calls): array {
            ++$calls;
            return ['id' => 42];
        };

        Assert::same($driver->execute('k', $op), ['id' => 42]);
        Assert::same($driver->execute('k', $op), ['id' => 42]);
        Assert::same($calls, 1);
        Assert::same($redis->calls[0]['args'][2], '1');
        Assert::same($redis->calls[1]['args'][0], 'M:tok-1');
        Assert::same($redis->store['amo:k']['value'], 'R:' . \serialize(['id' => 42]));
    }

    public function aDuplicateBeforeTheResultLandsGetsNull(): void
    {
        // Inside the first operation only the marker exists, so the cache has nothing to replay yet.
        $driver = $this->driver(new FakeRedisCommands(), cacheResult: true);
        $inner = 'unset';

        $driver->execute('k', static function () use ($driver, &$inner): string {
            $inner = $driver->execute('k', static fn(): string => 'never');
            return 'v';
        });

        Assert::null($inner);
    }

    public function aNullResultIsNotWritten(): void
    {
        $redis = new FakeRedisCommands();
        $this->driver($redis, cacheResult: true)->execute('k', static fn(): mixed => null);

        Assert::count($redis->calls, 1);
    }

    public function anUncacheableResultIsUnwrappedButNotCached(): void
    {
        $redis = new FakeRedisCommands();
        $driver = $this->driver($redis, cacheResult: true);

        Assert::same($driver->execute('k', static fn(): Uncacheable => new Uncacheable('v')), 'v');
        Assert::count($redis->calls, 1);
        Assert::null($driver->execute('k', static fn(): string => 'again'));
    }

    public function theResultDoesNotOverwriteAnotherCallersMarker(): void
    {
        // The marker expired mid-operation and another caller claimed the key: the stale result is dropped.
        $redis = new FakeRedisCommands();
        $driver = $this->driver($redis, cacheResult: true);

        $driver->execute('k', static function () use ($redis): string {
            $redis->store['amo:k'] = ['value' => 'M:other', 'ttlMs' => 1000];
            return 'v';
        });

        Assert::same($redis->store['amo:k']['value'], 'M:other');
    }

    public function aLostResultWriteIsLogged(): void
    {
        $redis = new FakeRedisCommands();
        $logger = $this->logger();
        $driver = new RedisAtMostOnceDriver($redis, $this->tokens(), 'amo:', 60, cacheResult: true, logger: $logger);

        $result = $driver->execute('k', static function () use ($redis): string {
            $redis->store['amo:k'] = ['value' => 'M:other', 'ttlMs' => 1000];
            return 'v';
        });

        Assert::same($result, 'v');
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['level'], 'warning');
        Assert::same(
            $logger->records[0]['message'],
            'Idempotency at-most-once result for key "{key}" was not cached: its marker expired or was '
            . 'claimed again before the effect finished (outlived the {ttl}s dedup window?), so a '
            . 'duplicate can run the effect again.',
        );
        Assert::same($logger->records[0]['context'], ['key' => 'k', 'ttl' => 60]);
    }

    #[DataSet([false, 'bool'], 'false')]
    #[DataSet([null, 'null'], 'nil')]
    #[DataSet(['OK', 'string'], 'status string')]
    public function anUnexpectedResultWriteReplyIsLoggedAsSuch(mixed $reply, string $type): void
    {
        $logger = $this->logger();
        $driver = new RedisAtMostOnceDriver(
            $this->replying([1], $reply),
            $this->tokens(),
            'amo:',
            60,
            cacheResult: true,
            logger: $logger,
        );

        Assert::same($driver->execute('k', static fn(): string => 'v'), 'v');
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['level'], 'warning');
        Assert::same(
            $logger->records[0]['message'],
            'Idempotency at-most-once result for key "{key}" may not be cached: unexpected reply '
            . '({replyType}) to the result write; the effect ran.',
        );
        Assert::same($logger->records[0]['context'], ['key' => 'k', 'replyType' => $type, 'reply' => $reply]);
    }

    public function aStoredResultLogsNothing(): void
    {
        $logger = $this->logger();
        $driver = new RedisAtMostOnceDriver(new FakeRedisCommands(), $this->tokens(), 'amo:', cacheResult: true, logger: $logger);

        $driver->execute('k', static fn(): string => 'v');

        Assert::count($logger->records, 0);
    }

    public function aFailedResultWriteIsLoggedAndTheValueStillReturned(): void
    {
        $failure = new \RuntimeException('connection lost');
        $logger = $this->logger();
        $driver = new RedisAtMostOnceDriver(
            new FakeRedisCommands($failure),
            $this->tokens(),
            'amo:',
            cacheResult: true,
            logger: $logger,
        );

        Assert::same($driver->execute('k', static fn(): string => 'v'), 'v');
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['level'], 'warning');
        Assert::same(
            $logger->records[0]['message'],
            'Idempotency at-most-once result for key "{key}" was not cached; the effect ran, '
            . 'a duplicate will get null.',
        );
        Assert::same($logger->records[0]['context']['key'], 'k');
        Assert::same($logger->records[0]['context']['exception'], $failure);
    }

    public function aFailedResultWriteWithoutALoggerIsSwallowed(): void
    {
        $driver = new RedisAtMostOnceDriver(
            new FakeRedisCommands(new \RuntimeException('connection lost')),
            $this->tokens(),
            'amo:',
            cacheResult: true,
        );

        Assert::same($driver->execute('k', static fn(): string => 'v'), 'v');
    }

    public function aCustomSerializerEncodesAndDecodesTheResult(): void
    {
        $redis = new FakeRedisCommands();
        $driver = new RedisAtMostOnceDriver($redis, $this->tokens(), 'amo:', serializer: new JsonSerializer(), cacheResult: true);

        $driver->execute('k', static fn(): array => ['id' => 42]);

        Assert::same($redis->store['amo:k']['value'], 'R:{"id":42}');
        Assert::same($driver->execute('k', static fn(): array => []), ['id' => 42]);
    }

    #[DataSet([[0]], 'bare refusal')]
    #[DataSet([[0, 'M:other']], 'refusal with a marker')]
    public function aRefusalReplyDoesNotRunTheEffect(array $reply): void
    {
        $calls = 0;

        $result = $this->driver($this->replying($reply), cacheResult: true)->execute('k', static function () use (&$calls): string {
            ++$calls;
            return 'v';
        });

        Assert::null($result);
        Assert::same($calls, 0);
    }

    /**
     * Refusing on a reply it cannot read would ACK a lost effect; failing before the effect keeps it at most once.
     */
    #[DataSet([false], 'false')]
    #[DataSet([null], 'nil')]
    #[DataSet(['OK'], 'status string')]
    #[DataSet([1], 'bare integer')]
    #[DataSet([[]], 'empty list')]
    #[DataSet([[2]], 'unknown code')]
    #[DataSet([['1']], 'string code')]
    #[DataSet([[1, 'x']], 'claim with a value')]
    #[DataSet([[0, 'x', 'y']], 'refusal with extra values')]
    public function anUnexpectedClaimReplyThrowsBeforeTheEffect(mixed $reply): void
    {
        $calls = 0;

        try {
            $this->driver($this->replying($reply), cacheResult: true)->execute('k', static function () use (&$calls): string {
                ++$calls;
                return 'v';
            });
            Assert::fail('an unexpected claim reply must throw');
        } catch (IdempotencyException $e) {
            Assert::same($e::class, IdempotencyException::class);
        }

        Assert::same($calls, 0);
    }

    private function driver(RedisCommands $redis, int $retentionTtl = 86400, bool $cacheResult = false): RedisAtMostOnceDriver
    {
        return new RedisAtMostOnceDriver($redis, $this->tokens(), 'amo:', $retentionTtl, cacheResult: $cacheResult);
    }

    /**
     * Answers each `eval()` with the next reply in turn; the last one repeats.
     */
    private function replying(mixed ...$replies): RedisCommands
    {
        return new class(\array_values($replies)) implements RedisCommands {
            public function __construct(
                /** @var list<mixed> */
                private array $replies,
            ) {}

            public function eval(string $script, array $keys, array $args): mixed
            {
                return \count($this->replies) > 1 ? \array_shift($this->replies) : $this->replies[0];
            }

            public function hgetall(string $key): array
            {
                return [];
            }

            public function pttl(string $key): int
            {
                return -2;
            }
        };
    }

    private function tokens(): TokenFactory
    {
        return new class implements TokenFactory {
            private int $sequence = 0;

            public function create(): string
            {
                return 'tok-' . ++$this->sequence;
            }
        };
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string, context: array}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
