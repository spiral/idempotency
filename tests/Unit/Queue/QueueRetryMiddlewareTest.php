<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Queue;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\Exception\OutcomeUnknownException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Queue\QueueRetryMiddleware;
use Spiral\Idempotency\Queue\RetryableLockException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(QueueRetryMiddleware::class)]
final class QueueRetryMiddlewareTest
{
    private function call(): IdempotencyCall
    {
        return new IdempotencyCall(
            context: new \stdClass(),
            operation: static fn(): mixed => null,
            options: new ExecuteOptions(),
        );
    }

    public function passesThroughReturnValue(): void
    {
        $middleware = new QueueRetryMiddleware();

        $result = $middleware->process($this->call(), static fn(IdempotencyCall $call): mixed => 'done');

        Assert::same($result, 'done');
    }

    public function passesThroughNull(): void
    {
        $middleware = new QueueRetryMiddleware();

        $result = $middleware->process($this->call(), static fn(IdempotencyCall $call): mixed => null);

        Assert::same($result, null);
    }

    public function acksOutcomeUnknownDuplicate(): void
    {
        // An AtMostOnce duplicate must neither fail nor re-enqueue: no throwable reaches the retry policy.
        $middleware = new QueueRetryMiddleware();

        $result = $middleware->process($this->call(), static function (IdempotencyCall $call): never {
            throw new OutcomeUnknownException('k');
        });

        Assert::null($result);
    }

    public function mapsLockedExceptionToRetryable(): void
    {
        $middleware = new QueueRetryMiddleware();
        $locked = new LockedException(new Locked('k', 7, new \DateTimeImmutable()));

        $thrown = null;
        try {
            $middleware->process($this->call(), static function (IdempotencyCall $call) use ($locked): mixed {
                throw $locked;
            });
            Assert::fail('a LockedException must be mapped to a RetryableLockException');
        } catch (RetryableLockException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::true($thrown->isRetryable());
        Assert::same($thrown->getRetryPolicy()->getDelay(0), 7);
        Assert::same($thrown->getPrevious(), $locked);
    }

    public function honorsMaxLockRetriesBudget(): void
    {
        $middleware = new QueueRetryMiddleware(maxLockRetries: 3);
        $locked = new LockedException(new Locked('k', 7, new \DateTimeImmutable()));

        $thrown = null;
        try {
            $middleware->process($this->call(), static function (IdempotencyCall $call) use ($locked): mixed {
                throw $locked;
            });
            Assert::fail('a LockedException must be mapped to a RetryableLockException');
        } catch (RetryableLockException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        $policy = $thrown->getRetryPolicy();
        Assert::notNull($policy);
        Assert::true($policy->isRetryable($thrown, 2));
        Assert::false($policy->isRetryable($thrown, 3));
    }

    public function doesNotCatchMissingKeyException(): void
    {
        $middleware = new QueueRetryMiddleware();

        Expect::exception(MissingKeyException::class);

        $middleware->process($this->call(), static function (IdempotencyCall $call): mixed {
            throw new MissingKeyException('no key');
        });
    }
}
