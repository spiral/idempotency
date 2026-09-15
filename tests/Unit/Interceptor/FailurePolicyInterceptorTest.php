<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Interceptor;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingHandler;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingIdempotency;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\Target;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class FailurePolicyFixture
{
    #[Idempotent(storage: 'jobs', key: 'id')]
    public function transportDefault(string $id): string
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the result.');
    }

    #[Idempotent(storage: 'jobs', key: 'id', failurePolicy: FailurePolicy::Release)]
    public function releases(string $id): string
    {
        throw new \LogicException('Not invoked directly.');
    }

    #[Idempotent(storage: 'jobs', key: 'id', failurePolicy: FailurePolicy::Cache)]
    public function caches(string $id): string
    {
        throw new \LogicException('Not invoked directly.');
    }
}

#[Test]
#[Covers(PipelineIdempotencyInterceptor::class)]
#[Covers(FailurePolicy::class)]
final class FailurePolicyInterceptorTest
{
    private RecordingIdempotency $storage;

    /**
     * An interceptor over an empty resolution stack: the attribute's arg-path supplies the key, so the
     * double sees exactly the options the interceptor composed.
     */
    private function interceptor(FailurePolicy $default): PipelineIdempotencyInterceptor
    {
        $registry = new IdempotencyRegistry();
        $this->storage = new RecordingIdempotency();
        $registry->register('jobs', $this->storage, Guarantee::AtLeastOnce);

        $container = new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new \LogicException("Unexpected service {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            new DefaultArgumentKeyResolver(),
            $container,
            new IdempotencyConfig(['transports' => ['queue' => []]]),
            'queue',
            $default,
        );
    }

    private function context(string $method): CallContext
    {
        return new CallContext(
            Target::fromReflectionMethod(
                new \ReflectionMethod(FailurePolicyFixture::class, $method),
                new FailurePolicyFixture(),
            ),
            ['id' => 'job-1'],
        );
    }

    public function attributePolicyReachesExecuteOptions(): void
    {
        $interceptor = $this->interceptor(FailurePolicy::Cache);

        $interceptor->intercept($this->context('releases'), new RecordingHandler());

        Assert::same($this->storage->calls[0]->options?->failurePolicy, FailurePolicy::Release);
    }

    public function attributePolicyWinsOverTheTransportDefault(): void
    {
        $interceptor = $this->interceptor(FailurePolicy::Release);

        $interceptor->intercept($this->context('caches'), new RecordingHandler());

        Assert::same($this->storage->calls[0]->options?->failurePolicy, FailurePolicy::Cache);
    }

    public function transportDefaultAppliesWhenTheAttributeIsSilent(): void
    {
        $interceptor = $this->interceptor(FailurePolicy::Release);

        $interceptor->intercept($this->context('transportDefault'), new RecordingHandler());

        Assert::same($this->storage->calls[0]->options?->failurePolicy, FailurePolicy::Release);
    }
}
