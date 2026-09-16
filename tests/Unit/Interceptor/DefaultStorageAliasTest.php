<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Interceptor;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingHandler;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingIdempotency;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\Target;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Counts the transport stack's entries: a misconfigured alias must be rejected before the resolution
 * pipeline runs, so this never sees the call.
 */
final class CountingMiddleware implements ResolutionMiddleware
{
    public int $calls = 0;

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        ++$this->calls;

        return $next($call);
    }
}

final class DefaultStorageFixture
{
    #[Idempotent(key: 'id')]
    public function fromDefault(string $id): string
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the result.');
    }

    #[Idempotent(storage: 'explicit', key: 'id')]
    public function fromAttribute(string $id): string
    {
        throw new \LogicException('Not invoked directly.');
    }
}

#[Test]
#[Covers(PipelineIdempotencyInterceptor::class)]
#[Covers(IdempotencyRegistry::class)]
#[Covers(Idempotent::class)]
final class DefaultStorageAliasTest
{
    /** @var array<non-empty-string, RecordingIdempotency> */
    private array $storages = [];

    private CountingMiddleware $middleware;

    public function attributeWithoutStorageUsesTheDefaultAlias(): void
    {
        $interceptor = $this->interceptor('fallback');

        $interceptor->intercept($this->context('fromDefault'), new RecordingHandler());

        Assert::same(\count($this->storages['fallback']->calls), 1);
        Assert::same($this->storages['explicit']->calls, []);
    }

    public function explicitStorageWinsOverTheDefault(): void
    {
        $interceptor = $this->interceptor('fallback');

        $interceptor->intercept($this->context('fromAttribute'), new RecordingHandler());

        Assert::same(\count($this->storages['explicit']->calls), 1);
        Assert::same($this->storages['fallback']->calls, []);
    }

    public function attributeWithoutStorageAndWithoutADefaultThrows(): void
    {
        $interceptor = $this->interceptor(null);
        $handler = new RecordingHandler();

        try {
            $interceptor->intercept($this->context('fromDefault'), $handler);
            Assert::fail('An unresolvable storage alias must not be swallowed.');
        } catch (MisconfigurationException $e) {
            Assert::string($e->getMessage())->contains('no default');
        }

        // A misconfiguration is not an outcome of the call: it surfaces before the transport's resolution
        // stack, whose outcome middleware would otherwise turn it into a response, and before the action.
        Assert::same($this->middleware->calls, 0);
        Assert::same($handler->calls, []);
    }

    /**
     * @param non-empty-string|null $default
     */
    private function interceptor(?string $default): PipelineIdempotencyInterceptor
    {
        $registry = new IdempotencyRegistry($default);
        $this->storages = [];
        foreach (['fallback', 'explicit'] as $alias) {
            $this->storages[$alias] = new RecordingIdempotency();
            $registry->register($alias, $this->storages[$alias], Guarantee::AtLeastOnce);
        }

        $this->middleware = new CountingMiddleware();
        $container = new class($this->middleware) implements ContainerInterface {
            public function __construct(private readonly CountingMiddleware $middleware) {}

            public function get(string $id): object
            {
                return $id === CountingMiddleware::class
                    ? $this->middleware
                    : throw new \LogicException("Unexpected service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === CountingMiddleware::class;
            }
        };

        return new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            new DefaultArgumentKeyResolver(),
            $container,
            new IdempotencyConfig(['transports' => ['queue' => [CountingMiddleware::class]]]),
            'queue',
        );
    }

    private function context(string $method): CallContext
    {
        return new CallContext(
            Target::fromReflectionMethod(
                new \ReflectionMethod(DefaultStorageFixture::class, $method),
                new DefaultStorageFixture(),
            ),
            ['id' => 'job-1'],
        );
    }
}
