<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Queue;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Queue\QueueKeyMiddleware;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\Target;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

final class JobFixture
{
    public function consume(): void
    {
        throw new \LogicException('Not invoked directly.');
    }
}

#[Test]
#[Covers(QueueKeyMiddleware::class)]
final class QueueKeyMiddlewareTest
{
    /**
     * @param array<string, list<string>> $headers
     */
    private function jobContext(array $headers = [], mixed $id = null): CallContext
    {
        $target = Target::fromReflectionMethod(
            new \ReflectionMethod(JobFixture::class, 'consume'),
            new JobFixture(),
        );

        // Consume-side headers and the broker job id travel as call ARGUMENTS, matching Spiral's queue
        // Handler: new CallContext($target, ['driver', 'queue', 'id', 'payload', 'headers']).
        $arguments = ['headers' => $headers];
        if ($id !== null) {
            $arguments['id'] = $id;
        }

        return new CallContext($target, $arguments);
    }

    private function call(mixed $context, ?string $key = null, ?string $keyScope = null): IdempotencyCall
    {
        return new IdempotencyCall(
            context: $context,
            operation: static fn(): mixed => null,
            options: new ExecuteOptions(),
            key: $key,
            keyScope: $keyScope,
        );
    }

    public function passesThroughWhenKeyAlreadyResolved(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->jobContext(['Idempotency-Key' => ['job-1']]), key: 'existing');

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, 'existing');
    }

    public function passesThroughWhenContextNotAttributed(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call('not-a-context');

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, null);
    }

    public function extractsKeyFromJobHeader(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call(
            $this->jobContext(['Idempotency-Key' => ['job-1']]),
            keyScope: 'Op::run',
        );

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('job-1', 'Op::run'));
    }

    public function customHeaderName(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver(), 'X-Dedup');
        $call = $this->call($this->jobContext(['X-Dedup' => ['abc']]));

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('abc', null));
    }

    public function missingHeaderThrowsMissingKey(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->jobContext([]));

        Expect::exception(MissingKeyException::class);

        $middleware->process($call, static fn(IdempotencyCall $call): mixed => null);
    }

    public function blankHeaderThrowsMissingKey(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->jobContext(['Idempotency-Key' => ['']]));

        Expect::exception(MissingKeyException::class);

        $middleware->process($call, static fn(IdempotencyCall $call): mixed => null);
    }

    public function fallsBackToJobIdWhenHeaderAbsent(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver(), fallbackToJobId: true);
        $call = $this->call($this->jobContext(id: 'job-id-1'), keyScope: 'Op::run');

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('job-id-1', 'Op::run'));
    }

    public function headerWinsOverJobId(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver(), fallbackToJobId: true);
        $call = $this->call($this->jobContext(['Idempotency-Key' => ['from-header']], id: 'job-id-1'));

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('from-header', null));
    }

    public function fallsBackToJobIdWhenHeaderBlank(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver(), fallbackToJobId: true);
        $call = $this->call($this->jobContext(['Idempotency-Key' => ['']], id: 42));

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('42', null));
    }

    public function jobIdIgnoredWhenFallbackDisabled(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->jobContext(id: 'job-id-1'));

        Expect::exception(MissingKeyException::class);

        $middleware->process($call, static fn(IdempotencyCall $call): mixed => null);
    }

    public function missingJobIdThrowsMissingKeyWithFallbackEnabled(): void
    {
        $middleware = new QueueKeyMiddleware(new DefaultKeyResolver(), fallbackToJobId: true);
        $call = $this->call($this->jobContext());

        Expect::exception(MissingKeyException::class);

        $middleware->process($call, static fn(IdempotencyCall $call): mixed => null);
    }
}
