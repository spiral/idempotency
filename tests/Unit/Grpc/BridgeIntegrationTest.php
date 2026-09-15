<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Grpc;

use Google\Protobuf\StringValue;
use Spiral\Core\CompatiblePipelineBuilder;
use Spiral\Core\Container;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Grpc\GrpcKeyMiddleware;
use Spiral\Idempotency\Grpc\GrpcOutcomeMiddleware;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\KeyResolver;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Tests\Unit\Stub\StubIdempotencyContext;
use Spiral\Interceptors\HandlerInterface;
use Spiral\Interceptors\Handler\AutowireHandler;
use Spiral\RoadRunnerBridge\GRPC\Internal\Dispatcher;
use Spiral\RoadRunnerBridge\GRPC\Internal\Invoker;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * A gRPC service with a signature the RoadRunner {@see Method::parse()} accepts: `(ContextInterface, Message)`
 * returning a Message. {@see StringValue} is a real generated protobuf message (a google/protobuf well-known
 * type), so the invoker can actually serialize the result.
 */
final class PingService implements ServiceInterface
{
    public int $calls = 0;

    #[Idempotent(storage: 'grpc')]
    public function Ping(ContextInterface $ctx, StringValue $in): StringValue
    {
        ++$this->calls;

        return new StringValue(['value' => 'pong#' . $this->calls]);
    }
}

/**
 * A fire-once (AtMostOnce-shaped) driver: runs the operation the first time and answers `null` for every
 * duplicate — the shape that has no room in gRPC, where the invoker demands a Message.
 */
final class FireOnceDriverStub implements Idempotency
{
    /** @var array<string, true> */
    private array $seen = [];

    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        if (isset($this->seen[$key])) {
            return null;
        }

        $this->seen[$key] = true;

        return $operation(new StubIdempotencyContext($key));
    }
}

/**
 * Integration test against the REAL `spiral/roadrunner-bridge` wiring instead of a hand-rolled stand-in.
 *
 * Everything this library assumes about the gRPC transport is a BEHAVIOURAL contract of the bridge, not a
 * type dependency (we import nothing from it): the invoker builds
 * `CallContext(Target::fromPair($service, $method->name), [$grpcContext, $message])`, that target carries a
 * real {@see \ReflectionMethod} (so `#[Idempotent]` is discoverable), the pipeline result MUST be a
 * protobuf Message, and the dispatcher scope is named `grpc`. Nothing in a unit test with a fake call
 * context can catch a change in any of those, so drive the real {@see Invoker} here.
 */
#[Test]
#[Covers(PipelineIdempotencyInterceptor::class)]
#[Covers(GrpcKeyMiddleware::class)]
#[Covers(GrpcOutcomeMiddleware::class)]
final class BridgeIntegrationTest
{
    /**
     * Assemble the interceptor pipeline the way `GRPCBootloader::initInvoker()` does: the configured
     * interceptors resolved from the container, wrapped around an {@see AutowireHandler}, handed to the
     * bridge's {@see Invoker}.
     */
    private function invoker(Container $container, Idempotency $driver, Guarantee $guarantee): Invoker
    {
        $container->bindSingleton(KeyResolver::class, new DefaultKeyResolver());

        $registry = new IdempotencyRegistry();
        $registry->register('grpc', $driver, $guarantee);

        $config = new IdempotencyConfig([
            'transports' => ['grpc' => [GrpcOutcomeMiddleware::class, GrpcKeyMiddleware::class]],
        ]);

        $interceptor = new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            $container,
            $config,
            transport: 'grpc',
        );

        $handler = (new CompatiblePipelineBuilder())
            ->withInterceptors($interceptor)
            ->build(new AutowireHandler($container));
        \assert($handler instanceof HandlerInterface);

        return new Invoker($handler, $container);
    }

    private function lease(): LeaseIdempotency
    {
        $clock = new MutableClock();

        return new LeaseIdempotency(
            new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock),
            new Pipeline(),
            lockTtl: 30,
            retentionTtl: 3600,
        );
    }

    /**
     * The gRPC context as the RoadRunner server builds it per call: client metadata plus a fresh
     * response-header bag under its own class name.
     */
    private function context(ResponseHeaders $headers, string $key = 'pay-1'): ContextInterface
    {
        return new Context([
            'idempotency-key' => [$key],
            ResponseHeaders::class => $headers,
        ]);
    }

    private function decode(string $payload): StringValue
    {
        $message = new StringValue();
        $message->mergeFromString($payload);

        return $message;
    }

    public function deduplicatesThroughTheRealBridgeInvoker(): void
    {
        $service = new PingService();
        $method = Method::parse(new \ReflectionMethod($service, 'Ping'));
        $invoker = $this->invoker(new Container(), $this->lease(), Guarantee::AtLeastOnce);
        $input = (new StringValue(['value' => 'ping']))->serializeToString();

        $first = new ResponseHeaders();
        $out1 = $invoker->invoke($service, $method, $this->context($first), $input);

        $second = new ResponseHeaders();
        $out2 = $invoker->invoke($service, $method, $this->context($second), $input);

        // The attribute was found on the service method (so the target really carries reflection), the key
        // was read from the metadata the invoker passes as the first call argument, and the response was
        // replayed from the snapshot instead of re-running the method.
        Assert::same($service->calls, 1);
        Assert::same($this->decode($out1)->getValue(), 'pong#1');
        Assert::same($this->decode($out2)->getValue(), 'pong#1');

        Assert::same($first->get('idempotency-replay'), 'false');
        Assert::same($second->get('idempotency-replay'), 'true');
        // Namespaced by operation identity, so the same client key on another method cannot cross-replay.
        Assert::same($second->get('idempotency-key'), PingService::class . '::Ping:pay-1');
    }

    public function nullOutcomeSatisfiesTheInvokersMessageContract(): void
    {
        // `Invoker::resultToString(Message $result)`: a duplicate that answers null would raise a TypeError,
        // which the server reports as a worker error rather than a status. The middleware materializes the
        // response type the method declares instead.
        $service = new PingService();
        $method = Method::parse(new \ReflectionMethod($service, 'Ping'));
        $invoker = $this->invoker(new Container(), new FireOnceDriverStub(), Guarantee::AtMostOnce);
        $input = (new StringValue(['value' => 'ping']))->serializeToString();

        $invoker->invoke($service, $method, $this->context(new ResponseHeaders()), $input);
        $duplicate = $invoker->invoke($service, $method, $this->context(new ResponseHeaders()), $input);

        Assert::same($service->calls, 1);
        // A valid, empty StringValue on the wire — not an exception, and identical on every duplicate.
        Assert::same($this->decode($duplicate)->getValue(), '');
    }

    public function dispatcherScopeIsTheOneWeBindInto(): void
    {
        // Canary for GrpcIdempotencyBootloader, which binds the real interceptor into the `grpc` scope: if
        // the bridge ever renames its dispatcher scope, the binding would silently never be reached.
        // Read the attribute's arguments without instantiating it — the attribute class itself belongs to a
        // newer spiral/boot than this package requires.
        $scope = null;
        foreach ((new \ReflectionClass(Dispatcher::class))->getAttributes() as $attribute) {
            if ($attribute->getName() === 'Spiral\\Attribute\\DispatcherScope') {
                $arguments = $attribute->getArguments();
                $scope = $arguments['scope'] ?? $arguments[0] ?? null;
                break;
            }
        }

        Assert::same($scope, 'grpc');
    }
}
