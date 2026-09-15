<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Core\Container;
use Spiral\Core\Scope;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingHandler;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingIdempotency;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\ArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\KeyResolver;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

final class PlainFixture
{
    public function plain(): string
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the result.');
    }
}

final class HttpAnnotatedFixture
{
    #[Idempotent(storage: 'payments', key: 'id')]
    public function pay(string $id): string
    {
        throw new \LogicException('Not invoked directly.');
    }
}

#[Test]
#[Covers(HttpIdempotencyBootloader::class)]
final class HttpIdempotencyBootloaderTest
{
    /**
     * The interceptor dependencies an application container would provide.
     */
    private function container(): Container
    {
        $container = new Container();
        $container->bindSingleton(IdempotencyRegistry::class, new IdempotencyRegistry());
        $container->bindSingleton(KeyResolver::class, new DefaultKeyResolver());
        $container->bindSingleton(ArgumentKeyResolver::class, new DefaultArgumentKeyResolver());
        $container->bindSingleton(IdempotencyConfig::class, new IdempotencyConfig([
            'transports' => ['http' => []],
        ]));

        $bootloader = new HttpIdempotencyBootloader();
        foreach ($bootloader->defineBindings() as $alias => $resolver) {
            $container->bind($alias, $resolver);
        }
        $bootloader->init($container);

        return $container;
    }

    /**
     * A no-attribute call the interceptor passes straight through to the handler.
     */
    private function passthroughCall(): array
    {
        $context = new CallContext(
            \Spiral\Interceptors\Context\Target::fromReflectionMethod(
                new \ReflectionMethod(PlainFixture::class, 'plain'),
                new PlainFixture(),
            ),
        );

        $handler = new class implements HandlerInterface {
            public function handle(CallContextInterface $context): mixed
            {
                return 'handled';
            }
        };

        return [$context, $handler];
    }

    public function resolvesRealInterceptorInsideHttpScope(): void
    {
        $container = $this->container();

        $interceptor = $container->runScope(
            new Scope(name: 'http'),
            static fn(IdempotencyInterceptor $interceptor): object => $interceptor,
        );

        Assert::instanceOf($interceptor, PipelineIdempotencyInterceptor::class);
    }

    public function interceptorIsASingletonOfTheHttpScope(): void
    {
        $container = $this->container();

        [$first, $second] = $container->runScope(
            new Scope(name: 'http'),
            static fn(ContainerInterface $scoped): array => [
                $scoped->get(IdempotencyInterceptor::class),
                $scoped->get(IdempotencyInterceptor::class),
            ],
        );

        Assert::same($first, $second);
    }

    public function rootYieldsProxyThatForwardsInsideHttpScope(): void
    {
        $container = $this->container();

        // Root resolution succeeds (a domain core built in root gets this proxy) ...
        $proxy = $container->get(IdempotencyInterceptor::class);
        Assert::instanceOf($proxy, IdempotencyInterceptor::class);
        // ... but it is not the real interceptor: it forwards per call.
        Assert::false($proxy instanceof PipelineIdempotencyInterceptor);

        [$context, $handler] = $this->passthroughCall();

        $result = $container->runScope(
            new Scope(name: 'http'),
            static fn(): mixed => $proxy->intercept($context, $handler),
        );

        Assert::same($result, 'handled');
    }

    public function httpFlavorDefaultsToCachingTheFailure(): void
    {
        // Over HTTP the client repeats the request itself: a failure is an outcome of this key, so a
        // repeat with the same Idempotency-Key must answer the same way instead of re-running.
        $container = $this->container();
        $storage = new RecordingIdempotency();
        $registry = $container->get(IdempotencyRegistry::class);
        \assert($registry instanceof IdempotencyRegistry);
        $registry->register('payments', $storage, Guarantee::AtLeastOnce);

        $context = new CallContext(
            \Spiral\Interceptors\Context\Target::fromReflectionMethod(
                new \ReflectionMethod(HttpAnnotatedFixture::class, 'pay'),
                new HttpAnnotatedFixture(),
            ),
            ['id' => 'pay-1'],
        );

        $container->runScope(
            new Scope(name: 'http'),
            static fn(IdempotencyInterceptor $interceptor): mixed => $interceptor->intercept(
                $context,
                new RecordingHandler(),
            ),
        );

        Assert::same($storage->calls[0]->options?->failurePolicy, FailurePolicy::Cache);
    }

    public function proxyInvokedOutsideTransportScopeFailsFast(): never
    {
        $container = $this->container();

        $proxy = $container->get(IdempotencyInterceptor::class);
        [$context, $handler] = $this->passthroughCall();

        Expect::exception(MisconfigurationException::class);

        // A scope with no transport binding on the chain: the proxy's fallback must fire.
        $container->runScope(
            new Scope(name: 'console'),
            static fn(): mixed => $proxy->intercept($context, $handler),
        );
    }
}
