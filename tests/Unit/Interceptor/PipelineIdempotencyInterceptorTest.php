<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Interceptor;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Spiral\Core\Container;
use Spiral\Core\ContainerScope;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Http\DomainFailureRenderer;
use Spiral\Idempotency\Http\HttpKeyMiddleware;
use Spiral\Idempotency\Http\HttpOutcomeMiddleware;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\KeyResolver;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\ReplayableFailure;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\Context\Target;
use Spiral\Interceptors\HandlerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class StringableUid implements \Stringable
{
    public function __construct(private readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

final class AnnotatedFixture
{
    #[Idempotent(storage: 'http', key: 'key')]
    public function withKey(string $key): ResponseInterface
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the response.');
    }

    #[Idempotent(storage: 'http')]
    public function fromHeader(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    #[Idempotent(storage: 'http', key: 'nope')]
    public function unresolvableKey(string $other): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    #[Idempotent(storage: 'http', key: 'payload.id')]
    public function withObjectKey(object $payload): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    public function plain(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    // Two distinct endpoints sharing the 'http' storage alias, keyed from the header (no explicit key):
    // default scope namespaces each by its own operation identity.
    #[Idempotent(storage: 'http')]
    public function endpointA(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    #[Idempotent(storage: 'http')]
    public function endpointB(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    // Two endpoints that deliberately share one key space via an explicit scope name.
    #[Idempotent(storage: 'http', scope: 'shared-op')]
    public function sharedA(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    #[Idempotent(storage: 'http', scope: 'shared-op')]
    public function sharedB(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    // Two endpoints opting out of namespacing into one global key space (pre-namespacing behaviour).
    #[Idempotent(storage: 'http', scope: Idempotent::SCOPE_GLOBAL)]
    public function globalA(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    #[Idempotent(storage: 'http', scope: Idempotent::SCOPE_GLOBAL)]
    public function globalB(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }
}

/**
 * Handler stub: counts invocations and returns a fresh response whose body reveals the run number,
 * so a replayed (cached) response is detectable by its stale body.
 */
final class CountingHandler implements HandlerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly Psr17Factory $factory,
        private readonly int $status = 200,
    ) {}

    public function handle(CallContextInterface $context): mixed
    {
        ++$this->calls;

        return $this->factory
            ->createResponse($this->status)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('run#' . $this->calls));
    }
}

/**
 * A domain failure that opts into faithful, exact-type replay via {@see ReplayableFailure}.
 */
final class DeclinedFailureStub extends \DomainException implements ReplayableFailure
{
    public function __construct(public readonly int $declineCode)
    {
        parent::__construct('declined');
    }

    public function toReplayPayload(): array
    {
        return ['declineCode' => $this->declineCode];
    }

    public static function fromReplayPayload(array $payload): static
    {
        return new self((int) $payload['declineCode']);
    }
}

/**
 * Handler stub that throws a replayable domain failure, counting invocations.
 */
final class ThrowingHandler implements HandlerInterface
{
    public int $calls = 0;

    public function handle(CallContextInterface $context): mixed
    {
        ++$this->calls;

        throw new DeclinedFailureStub(402);
    }
}

/**
 * A domain failure that does NOT opt into replay — stands in for an exception the application does not
 * own (thrown by a library), where {@see ReplayableFailure} is not an option.
 */
final class PlainDeclineStub extends \DomainException {}

/**
 * Handler stub that throws whatever it is given, counting invocations.
 */
final class FailingHandler implements HandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly \Throwable $failure) {}

    public function handle(CallContextInterface $context): mixed
    {
        ++$this->calls;

        throw $this->failure;
    }
}

/**
 * Renders domain failures into a fixed-status response, or declines them (null) when constructed with a
 * null status. Counts consultations, so a test can assert a non-Domain failure never reaches it.
 */
final class DomainFailureRendererStub implements DomainFailureRenderer
{
    public int $calls = 0;

    public function __construct(
        private readonly Psr17Factory $factory,
        private readonly ?int $status = 422,
    ) {}

    public function render(\Throwable $failure): ?ResponseInterface
    {
        ++$this->calls;

        return $this->status === null ? null : $this->factory
            ->createResponse($this->status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(
                (string) \json_encode(['error' => 'declined', 'message' => $failure->getMessage()]),
            ));
    }
}

#[Test]
#[Covers(PipelineIdempotencyInterceptor::class)]
#[Covers(HttpOutcomeMiddleware::class)]
final class PipelineIdempotencyInterceptorTest
{
    private Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    /**
     * @param list<class-string>|null $stack resolution-middleware order (defaults to the canonical
     *        [outcome, key] — outcome outermost)
     * @param DomainFailureRenderer|null $failures bound into the outcome middleware; null keeps
     *        the throw-through behaviour for domain failures
     */
    private function interceptor(
        ?array $stack = null,
        ?DefaultLeaseManager $manager = null,
        ?DomainFailureRenderer $failures = null,
    ): PipelineIdempotencyInterceptor {
        $clock = new MutableClock();
        $manager ??= new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);
        $registry = new IdempotencyRegistry();
        $registry->register(
            'http',
            new LeaseIdempotency($manager, new Pipeline(), lockTtl: 30, retentionTtl: 3600),
            Guarantee::AtLeastOnce,
        );

        // Container resolves the HTTP resolution middleware named in the config stack.
        $container = new class($this->psr17, $failures) implements ContainerInterface {
            public function __construct(
                private readonly Psr17Factory $psr17,
                private readonly ?DomainFailureRenderer $failures,
            ) {}

            public function get(string $id): object
            {
                return match ($id) {
                    HttpKeyMiddleware::class => new HttpKeyMiddleware(new DefaultKeyResolver()),
                    HttpOutcomeMiddleware::class => new HttpOutcomeMiddleware(
                        $this->psr17,
                        $this->psr17,
                        failures: $this->failures,
                    ),
                    default => throw new \LogicException("Unexpected service {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return \in_array($id, [HttpKeyMiddleware::class, HttpOutcomeMiddleware::class], true);
            }
        };

        $config = new IdempotencyConfig([
            'default' => 'http',
            'storages' => [],
            'transports' => ['http' => $stack ?? [HttpOutcomeMiddleware::class, HttpKeyMiddleware::class]],
        ]);

        return new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            new DefaultArgumentKeyResolver(),
            $container,
            $config,
            'http',
        );
    }

    /**
     * @param non-empty-string $method
     * @param array<array-key, mixed> $arguments
     */
    private function context(string $method, array $arguments = [], ?ServerRequestInterface $request = null): CallContext
    {
        $target = Target::fromReflectionMethod(
            new \ReflectionMethod(AnnotatedFixture::class, $method),
            new AnnotatedFixture(),
        );

        return new CallContext(
            $target,
            $arguments,
            $request === null ? [] : [ServerRequestInterface::class => $request],
        );
    }

    public function cachesResponseAndReplaysWithoutRerunning(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'abc']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'abc']), $handler);

        Assert::same($handler->calls, 1);
        Assert::same((string) $first->getBody(), 'run#1');
        Assert::same((string) $second->getBody(), 'run#1');
        Assert::same($first->getHeaderLine('Idempotency-Replay'), 'false');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
        // The response carries the FINAL (composite) key: operation identity + ':' separator + raw material.
        Assert::same($second->getHeaderLine('Idempotency-Key'), AnnotatedFixture::class . '::withKey:abc');
    }

    public function differentKeysRunSeparately(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $interceptor->intercept($this->context('withKey', ['key' => 'a']), $handler);
        $interceptor->intercept($this->context('withKey', ['key' => 'b']), $handler);

        Assert::same($handler->calls, 2);
    }

    public function resolvesKeyFromBodyWhenNotInRouteArgs(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        // key: null on the attribute — the transport middleware supplies it. With no header present,
        // HttpKeyMiddleware falls to the "key" body/query field.
        $request = $this->psr17->createServerRequest('POST', '/charge')->withParsedBody(['key' => 'from-body']);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('fromHeader', [], $request), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('fromHeader', [], $request), $handler);

        Assert::same($handler->calls, 1);
        // Composite key: the transport middleware namespaces the body-supplied key by operation identity.
        Assert::same($first->getHeaderLine('Idempotency-Key'), AnnotatedFixture::class . '::fromHeader:from-body');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
    }

    public function resolvesKeyFromHeaderWhenAttributeKeyIsNull(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $request = $this->psr17->createServerRequest('POST', '/charge')->withHeader('Idempotency-Key', 'hdr-1');

        $interceptor->intercept($this->context('fromHeader', [], $request), $handler);
        $interceptor->intercept($this->context('fromHeader', [], $request), $handler);

        Assert::same($handler->calls, 1);
    }

    public function missingKeyYieldsBadRequest(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        // No attribute key, and the request carries neither the header nor the body/query field.
        $request = $this->psr17->createServerRequest('POST', '/charge');

        /** @var ResponseInterface $response */
        $response = $interceptor->intercept($this->context('fromHeader', [], $request), $handler);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::string((string) $response->getBody())->contains('missing_idempotency_key');
        Assert::same($handler->calls, 0);
    }

    public function blankHeaderYieldsBadRequest(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        // A whitespace-only key trims to empty — the resolver rejects it as missing (DefaultKeyResolver branch).
        $request = $this->psr17->createServerRequest('POST', '/charge')->withHeader('Idempotency-Key', '   ');

        /** @var ResponseInterface $response */
        $response = $interceptor->intercept($this->context('fromHeader', [], $request), $handler);

        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->contains('missing_idempotency_key');
        Assert::same($handler->calls, 0);
    }

    public function unresolvableAttributeKeyPathFailsFast(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        // Explicit key-path ('nope') that resolves to nothing, AND a request carrying a header the
        // transport fallback would otherwise pick up. The mismatch is a misconfiguration (typo in the
        // path) and must fail fast — never silently switch the dedup basis to the header.
        $request = $this->psr17->createServerRequest('POST', '/charge')->withHeader('Idempotency-Key', 'hdr');

        $thrown = null;
        try {
            $interceptor->intercept($this->context('unresolvableKey', ['other' => 'x'], $request), $handler);
        } catch (MisconfigurationException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        // Key assertion: the header fallback did NOT kick in — the handler never ran.
        Assert::same($handler->calls, 0);
    }

    public function resolvesKeyFromStringableArgument(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        // Domain ids are value objects, not strings: the arg-path must stringify them rather than
        // treat the endpoint as misconfigured.
        $payload = new \stdClass();
        $payload->id = new StringableUid('uid-7');

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withObjectKey', ['payload' => $payload]), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withObjectKey', ['payload' => $payload]), $handler);

        Assert::same($handler->calls, 1);
        Assert::same($first->getHeaderLine('Idempotency-Key'), AnnotatedFixture::class . '::withObjectKey:uid-7');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
    }

    public function argumentWithoutStringFormStillFailsFast(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $payload = new \stdClass();
        $payload->id = ['nested' => 'array'];

        $thrown = null;
        try {
            $interceptor->intercept($this->context('withObjectKey', ['payload' => $payload]), $handler);
        } catch (MisconfigurationException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($handler->calls, 0);
    }

    public function thrownReplayableFailureReplaysSameClassOverHttp(): void
    {
        $interceptor = $this->interceptor();
        $handler = new ThrowingHandler();

        try {
            $interceptor->intercept($this->context('withKey', ['key' => 'rf']), $handler);
            Assert::fail('the first attempt must throw the domain failure');
        } catch (DeclinedFailureStub) {
            // first call: the replayable failure is cached
        }

        try {
            $interceptor->intercept($this->context('withKey', ['key' => 'rf']), $handler);
            Assert::fail('replay should rethrow the exact original type');
        } catch (DeclinedFailureStub $replayed) {
            // Same class as the first attempt → the app handler renders the same HTTP status.
            Assert::same($replayed->declineCode, 402);
        }

        // The handler ran once; the replay came from the cached snapshot.
        Assert::same($handler->calls, 1);
    }

    public function passesThroughWhenNoAttribute(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $interceptor->intercept($this->context('plain'), $handler);
        $interceptor->intercept($this->context('plain'), $handler);

        // No attribute → no idempotency: the handler runs every time.
        Assert::same($handler->calls, 2);
    }

    public function sameClientKeyOnDifferentEndpointsRunsSeparately(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        // The SAME client Idempotency-Key hitting two different endpoints that share the 'http' alias.
        $requestA = $this->psr17->createServerRequest('POST', '/a')->withHeader('Idempotency-Key', 'client-key');
        $requestB = $this->psr17->createServerRequest('POST', '/b')->withHeader('Idempotency-Key', 'client-key');

        $interceptor->intercept($this->context('endpointA', [], $requestA), $handler);
        $interceptor->intercept($this->context('endpointB', [], $requestB), $handler);

        // Default scope namespaces each by its own operation identity → distinct key spaces → both run.
        // No cross-endpoint replay of another endpoint's cached response.
        Assert::same($handler->calls, 2);
    }

    public function explicitScopeSharesKeySpace(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $requestA = $this->psr17->createServerRequest('POST', '/a')->withHeader('Idempotency-Key', 'k');
        $requestB = $this->psr17->createServerRequest('POST', '/b')->withHeader('Idempotency-Key', 'k');

        $interceptor->intercept($this->context('sharedA', [], $requestA), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('sharedB', [], $requestB), $handler);

        // Both methods declare scope: 'shared-op' → one shared key space → the second call replays.
        Assert::same($handler->calls, 1);
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
    }

    public function globalScopeOptsOutOfNamespacing(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $requestA = $this->psr17->createServerRequest('POST', '/a')->withHeader('Idempotency-Key', 'g');
        $requestB = $this->psr17->createServerRequest('POST', '/b')->withHeader('Idempotency-Key', 'g');

        $interceptor->intercept($this->context('globalA', [], $requestA), $handler);
        $interceptor->intercept($this->context('globalB', [], $requestB), $handler);

        // SCOPE_GLOBAL opts out of namespacing → one global key space → cross-endpoint replay (pre-fix
        // behaviour, kept as an explicit opt-out).
        Assert::same($handler->calls, 1);
    }

    public function bindsContextInIsolatedScopeWithoutLeaking(): void
    {
        // With a real Spiral container, dispatch() must expose IdempotencyContext to the action via an
        // isolated child scope — visible inside, but not leaked into the root container afterwards.
        $container = new Container();
        $container->bindSingleton(KeyResolver::class, new DefaultKeyResolver());
        $container->bindSingleton(ResponseFactoryInterface::class, $this->psr17);
        $container->bindSingleton(StreamFactoryInterface::class, $this->psr17);

        $clock = new MutableClock();
        $registry = new IdempotencyRegistry();
        $registry->register(
            'http',
            new LeaseIdempotency(
                new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock),
                new Pipeline(),
                lockTtl: 30,
                retentionTtl: 3600,
            ),
            Guarantee::AtLeastOnce,
        );
        $config = new IdempotencyConfig([
            'transports' => ['http' => [HttpOutcomeMiddleware::class, HttpKeyMiddleware::class]],
        ]);
        $interceptor = new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            new DefaultArgumentKeyResolver(),
            $container,
            $config,
            'http',
        );

        $handler = new class($this->psr17) implements HandlerInterface {
            public bool $contextVisible = false;

            public function __construct(private readonly Psr17Factory $factory) {}

            public function handle(CallContextInterface $context): mixed
            {
                $scope = ContainerScope::getContainer();
                $this->contextVisible = $scope !== null && $scope->has(IdempotencyContext::class)
                    && $scope->get(IdempotencyContext::class) instanceof IdempotencyContext;

                return $this->factory->createResponse(200)->withBody($this->factory->createStream('ok'));
            }
        };

        $interceptor->intercept($this->context('withKey', ['key' => 'z']), $handler);

        Assert::true($handler->contextVisible);                    // injectable inside the scope
        Assert::false($container->has(IdempotencyContext::class));  // not leaked into the root container
    }

    public function transientResponseIsNotCachedAndReRuns(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17, status: 503);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'x']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'x']), $handler);

        // 5xx is not cached (default policy): the key is released, so the second call re-runs.
        Assert::same($handler->calls, 2);
        Assert::same($first->getStatusCode(), 503);
        Assert::same($second->getStatusCode(), 503);
        Assert::same($second->getBody()->__toString(), 'run#2');
    }

    public function headersSurviveEitherMiddlewareOrder(): void
    {
        // The replay headers must not depend on whether outcome sits inside or outside the key middleware.
        $stacks = [
            'outcome-outer' => [HttpOutcomeMiddleware::class, HttpKeyMiddleware::class],
            'key-outer' => [HttpKeyMiddleware::class, HttpOutcomeMiddleware::class],
        ];

        foreach ($stacks as $label => $stack) {
            $interceptor = $this->interceptor($stack);
            $handler = new CountingHandler($this->psr17);
            $key = 'order-' . $label;
            $expected = AnnotatedFixture::class . '::withKey:' . $key;

            /** @var ResponseInterface $first */
            $first = $interceptor->intercept($this->context('withKey', ['key' => $key]), $handler);
            /** @var ResponseInterface $second */
            $second = $interceptor->intercept($this->context('withKey', ['key' => $key]), $handler);

            Assert::same($handler->calls, 1, $label);
            Assert::same($first->getHeaderLine('Idempotency-Key'), $expected, $label);
            Assert::same($first->getHeaderLine('Idempotency-Replay'), 'false', $label);
            Assert::same($second->getHeaderLine('Idempotency-Key'), $expected, $label);
            Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true', $label);
        }
    }

    public function conflictResponseCarriesIdempotencyKey(): void
    {
        $clock = new MutableClock();
        $manager = new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);

        // Occupy the key with an in-flight PROCESSING lease held by "someone else". The interceptor
        // namespaces the client key by operation identity, so the pre-acquired key must be the composite.
        $key = (new DefaultKeyResolver())->resolve('busy-key', AnnotatedFixture::class . '::withKey');
        $manager->acquire($key, 30);

        $interceptor = $this->interceptor(manager: $manager);
        $handler = new CountingHandler($this->psr17);

        /** @var ResponseInterface $response */
        $response = $interceptor->intercept($this->context('withKey', ['key' => 'busy-key']), $handler);

        Assert::same($response->getStatusCode(), 409);
        Assert::same($response->getHeaderLine('Idempotency-Key'), $key);
        Assert::same($handler->calls, 0);
    }

    public function replayDropsHopByHopHeaders(): void
    {
        $interceptor = $this->interceptor();

        // Handler emits body-framing / hop-by-hop headers that must not survive into a replay.
        $handler = new class($this->psr17) implements HandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly Psr17Factory $factory) {}

            public function handle(CallContextInterface $context): mixed
            {
                ++$this->calls;

                return $this->factory
                    ->createResponse(200)
                    ->withHeader('Content-Type', 'text/plain')
                    ->withHeader('Transfer-Encoding', 'chunked')
                    ->withHeader('Content-Length', '4')
                    ->withHeader('Connection', 'keep-alive')
                    ->withBody($this->factory->createStream('body'));
            }
        };

        $interceptor->intercept($this->context('withKey', ['key' => 'hbh']), $handler);
        /** @var ResponseInterface $replay */
        $replay = $interceptor->intercept($this->context('withKey', ['key' => 'hbh']), $handler);

        Assert::same($handler->calls, 1);
        Assert::same($replay->getHeaderLine('Idempotency-Replay'), 'true');
        Assert::false($replay->hasHeader('Transfer-Encoding'));
        Assert::false($replay->hasHeader('Connection'));
        // Body survives intact; the emitter re-derives Content-Length from it.
        Assert::same((string) $replay->getBody(), 'body');
        Assert::same($replay->getHeaderLine('Content-Type'), 'text/plain');
    }

    public function renderedDomainFailureReplaysWithTheSameStatus(): void
    {
        $renderer = new DomainFailureRendererStub($this->psr17);
        $interceptor = $this->interceptor(failures: $renderer);
        // A domain exception the application does not own: no ReplayableFailure possible.
        $handler = new FailingHandler(new PlainDeclineStub('card expired'));

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'render-1']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'render-1']), $handler);

        // The throw became a response INSIDE the operation, so it was snapshotted like any returned one:
        // one handler run, one rendering, and both attempts observe the SAME status and body.
        Assert::same($handler->calls, 1);
        Assert::same($renderer->calls, 1);
        Assert::same($first->getStatusCode(), 422);
        Assert::same($second->getStatusCode(), 422);
        Assert::string((string) $second->getBody())->contains('card expired');
        // ... and the replay is now observable on the failure path too (it was not, before rendering).
        Assert::same($first->getHeaderLine('Idempotency-Replay'), 'false');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
        Assert::same($second->getHeaderLine('Idempotency-Key'), AnnotatedFixture::class . '::withKey:render-1');
    }

    public function infrastructureFailureIsNeverRendered(): void
    {
        $renderer = new DomainFailureRendererStub($this->psr17);
        $interceptor = $this->interceptor(failures: $renderer);
        // \Error → Infrastructure: must stay a throwable so the driver frees the key and a retry re-runs.
        $handler = new FailingHandler(new \Error('boom'));

        $thrown = 0;
        foreach (['first', 'second'] as $_) {
            try {
                $interceptor->intercept($this->context('withKey', ['key' => 'render-infra']), $handler);
            } catch (\Error) {
                ++$thrown;
            }
        }

        Assert::same($thrown, 2);
        // Never consulted: rendering an infra error would cache a transient failure for the retention TTL.
        Assert::same($renderer->calls, 0);
        // The key was released on each attempt, so the operation really re-ran.
        Assert::same($handler->calls, 2);
    }

    public function declinedRenderingFallsBackToTheThrow(): void
    {
        // Renderer that owns nothing (returns null) — the documented fallback: behaviour is exactly the
        // same as with no renderer bound at all.
        $renderer = new DomainFailureRendererStub($this->psr17, status: null);
        $interceptor = $this->interceptor(failures: $renderer);
        $handler = new FailingHandler(new PlainDeclineStub('declined'));

        try {
            $interceptor->intercept($this->context('withKey', ['key' => 'render-null']), $handler);
            Assert::fail('the declined failure must be rethrown');
        } catch (PlainDeclineStub) {
            // first attempt: the original type reaches the application exception handler
        }

        try {
            $interceptor->intercept($this->context('withKey', ['key' => 'render-null']), $handler);
            Assert::fail('the cached domain failure must be rethrown on replay');
        } catch (CachedDomainFailureException $replayed) {
            // Replay of a non-replayable failure: the lightweight snapshot, mappable by originalClass.
            Assert::same($replayed->originalClass, PlainDeclineStub::class);
        }

        Assert::same($handler->calls, 1);
        Assert::same($renderer->calls, 1);
    }

    public function boundRendererIsPickedUpByAutowiring(): void
    {
        // The documented wiring: bind the interface, the middleware receives it. With a REAL container,
        // since the middleware's constructor parameter is optional (nullable + default) — the same
        // resolution path must also work with nothing bound (see bindsContextInIsolatedScopeWithoutLeaking).
        $container = new Container();
        $container->bindSingleton(KeyResolver::class, new DefaultKeyResolver());
        $container->bindSingleton(ResponseFactoryInterface::class, $this->psr17);
        $container->bindSingleton(StreamFactoryInterface::class, $this->psr17);
        $container->bindSingleton(DomainFailureRenderer::class, new DomainFailureRendererStub($this->psr17));

        $clock = new MutableClock();
        $registry = new IdempotencyRegistry();
        $registry->register(
            'http',
            new LeaseIdempotency(
                new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock),
                new Pipeline(),
                lockTtl: 30,
                retentionTtl: 3600,
            ),
            Guarantee::AtLeastOnce,
        );
        $config = new IdempotencyConfig([
            'transports' => ['http' => [HttpOutcomeMiddleware::class, HttpKeyMiddleware::class]],
        ]);
        $interceptor = new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            new DefaultArgumentKeyResolver(),
            $container,
            $config,
            'http',
        );
        $handler = new FailingHandler(new PlainDeclineStub('declined'));

        /** @var ResponseInterface $response */
        $response = $interceptor->intercept($this->context('withKey', ['key' => 'autowired']), $handler);

        // Rendered, not rethrown → the binding reached the middleware without any explicit factory.
        Assert::same($response->getStatusCode(), 422);
    }

    public function renderedTransientFailureIsNotCachedAndReRuns(): void
    {
        // A renderer may legitimately map a domain failure to 5xx; the cacheable predicate still governs,
        // so the outcome is NOT cached and the next attempt re-runs the operation.
        $renderer = new DomainFailureRendererStub($this->psr17, status: 503);
        $interceptor = $this->interceptor(failures: $renderer);
        $handler = new FailingHandler(new PlainDeclineStub('upstream down'));

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'render-503']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'render-503']), $handler);

        Assert::same($first->getStatusCode(), 503);
        Assert::same($second->getStatusCode(), 503);
        Assert::same($handler->calls, 2);
        Assert::same($renderer->calls, 2);
    }
}
