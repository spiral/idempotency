<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Grpc;

use Google\Rpc\RetryInfo;
use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Grpc\DomainFailureMapper;
use Spiral\Idempotency\Grpc\GrpcOutcomeMiddleware;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Pipeline\Retryable;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Tests\Unit\Stub\StubIdempotencyContext;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\Target;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\StatusCode;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class FakeGrpcMessage
{
    public function __construct(public string $payload = '') {}

    public function serializeToString(): string
    {
        return $this->payload;
    }

    public function mergeFromString(string $data): void
    {
        $this->payload = $data;
    }
}

/**
 * A service-specific status subclass: proves the exact type survives a replay (every GRPCException
 * subclass shares one final constructor, so the middleware can rebuild it).
 */
final class OrderRejectedException extends GRPCException
{
    protected const CODE = StatusCode::FAILED_PRECONDITION;
}

/**
 * A domain failure that is NOT a gRPC status — the case a {@see DomainFailureMapper} covers.
 */
final class PlainDeclineStub extends \DomainException {}

/**
 * Stands in for a generated gRPC service: the compiler always declares a concrete response type, which is
 * what lets the middleware materialize an empty answer for a dedup hit that cached no message.
 */
final class FakePingService
{
    public function Ping(FakeGrpcMessage $in): FakeGrpcMessage
    {
        throw new \LogicException('Not invoked directly — the pipeline terminal produces the result.');
    }
}

/**
 * A status the application marks as infrastructure: the classifier must keep it a throwable, so the key is
 * released and a retry re-runs instead of the status being cached as the outcome.
 */
final class RetryableStatusException extends GRPCException implements Retryable
{
    protected const CODE = StatusCode::FAILED_PRECONDITION;
}

/**
 * Maps {@see PlainDeclineStub} to a status; declines everything else (returns null).
 */
final class DomainFailureMapperStub implements DomainFailureMapper
{
    public int $calls = 0;

    public function __construct(private readonly ?int $code = StatusCode::FAILED_PRECONDITION) {}

    public function map(\Throwable $failure): ?GRPCExceptionInterface
    {
        ++$this->calls;

        return $this->code === null || !$failure instanceof PlainDeclineStub
            ? null
            : new GRPCException($failure->getMessage(), $this->code);
    }
}

#[Test]
#[Covers(GrpcOutcomeMiddleware::class)]
final class GrpcOutcomeMiddlewareTest
{
    private function context(): IdempotencyContext
    {
        return new StubIdempotencyContext();
    }

    private function call(\Closure $operation): IdempotencyCall
    {
        return new IdempotencyCall(
            context: new \stdClass(),
            operation: $operation,
            options: new ExecuteOptions(),
        );
    }

    /**
     * A call whose context is built exactly as `spiral/roadrunner-bridge`'s `GRPC\Internal\Invoker` builds
     * it: `new CallContext(Target::fromPair($service, $method->name), [$grpcContext, $message])`.
     */
    private function serviceCall(\Closure $operation, ?ContextInterface $grpc = null): IdempotencyCall
    {
        return new IdempotencyCall(
            context: new CallContext(
                Target::fromPair(new FakePingService(), 'Ping'),
                [$grpc ?? new \stdClass(), new FakeGrpcMessage('in')],
            ),
            operation: $operation,
            options: new ExecuteOptions(),
        );
    }

    /**
     * The gRPC context as the RoadRunner server builds it: metadata plus the mutable response-header bag
     * under its own class name (`Server::serve()` packs that bag into the reply, error branch included).
     */
    private function grpcContext(ResponseHeaders $headers): ContextInterface
    {
        return new Context([ResponseHeaders::class => $headers]);
    }

    /**
     * A REAL lease driver (in-memory storage): the snapshot round-trips through the serializer and the
     * cache/replay + Uncacheable semantics are exercised, not just this middleware's own encoding.
     */
    private function driver(): LeaseIdempotency
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
     * @return \Closure(IdempotencyCall): mixed the driver handler the middleware wraps, as the interceptor
     *         terminal does
     */
    private function through(LeaseIdempotency $driver): \Closure
    {
        return static fn(IdempotencyCall $c): mixed => $driver->execute('k', $c->operation, $c->options);
    }

    public function snapshotsAndRebuildsMessageOnFreshCall(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $ctx = $this->context();
        $call = $this->call(static fn(): mixed => new FakeGrpcMessage('hello'));

        // The `$next` executes the encoded operation, mimicking the driver handler that caches whatever
        // the operation returns (here: the [class, bytes] snapshot produced by encode()).
        $next = static fn(IdempotencyCall $c): mixed => ($c->operation)($ctx);

        $result = $middleware->process($call, $next);

        Assert::true($result instanceof FakeGrpcMessage);
        /** @var FakeGrpcMessage $result */
        Assert::same($result->payload, 'hello');
    }

    public function rebuildsMessageFromCachedSnapshot(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static function (): never {
            throw new \LogicException('The operation must not run on a replay.');
        });

        // Simulate a replay: `$next` returns a previously cached snapshot without invoking the operation.
        $next = static fn(IdempotencyCall $c): mixed => [
            '__idempotency_grpc_message__' => true,
            'class' => FakeGrpcMessage::class,
            'bytes' => 'world',
        ];

        $result = $middleware->process($call, $next);

        Assert::true($result instanceof FakeGrpcMessage);
        /** @var FakeGrpcMessage $result */
        Assert::same($result->payload, 'world');
    }

    public function passesThroughNonMessageResult(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $ctx = $this->context();
        $call = $this->call(static fn(): mixed => 'plain');

        $next = static fn(IdempotencyCall $c): mixed => ($c->operation)($ctx);

        $result = $middleware->process($call, $next);

        Assert::same($result, 'plain');
    }

    public function mapsLockedToAborted(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static fn(): mixed => null);
        $locked = new LockedException(new Locked('k', 7, new \DateTimeImmutable()));

        $next = static function (IdempotencyCall $c) use ($locked): never {
            throw $locked;
        };

        $thrown = null;
        try {
            $middleware->process($call, $next);
            Assert::fail('a LockedException must be mapped to a GRPCException');
        } catch (GRPCException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getCode(), StatusCode::ABORTED);
        Assert::same($thrown->getPrevious(), $locked);
    }

    public function abortedCarriesRetryInfoWithTheDelay(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static fn(): mixed => null);
        $locked = new LockedException(new Locked('k', 7, new \DateTimeImmutable()));

        $next = static function (IdempotencyCall $c) use ($locked): never {
            throw $locked;
        };

        $thrown = null;
        try {
            $middleware->process($call, $next);
        } catch (GRPCExceptionInterface $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        // The canonical gRPC counterpart of Retry-After: a machine-readable delay in the status details,
        // which the human-readable message cannot give a client.
        $details = $thrown->getDetails();
        Assert::same(\count($details), 1);
        Assert::true($details[0] instanceof RetryInfo);
        /** @var RetryInfo $info */
        $info = $details[0];
        $delay = $info->getRetryDelay();
        Assert::notNull($delay);
        Assert::same((int) $delay->getSeconds(), 7);
    }

    public function mapsMissingKeyToInvalidArgument(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static fn(): mixed => null);

        $next = static function (IdempotencyCall $c): never {
            throw new MissingKeyException('no key');
        };

        $thrown = null;
        try {
            $middleware->process($call, $next);
            Assert::fail('a MissingKeyException must be mapped to a GRPCException');
        } catch (GRPCException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getCode(), StatusCode::INVALID_ARGUMENT);
    }

    public function thrownStatusReplaysAsTheSameStatusAndType(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $calls = 0;
        $call = $this->call(static function () use (&$calls): never {
            ++$calls;
            throw new OrderRejectedException('order already shipped');
        });

        $statuses = [];
        foreach (['first', 'replay'] as $_) {
            try {
                $middleware->process($call, $this->through($driver));
                Assert::fail('the status must be rethrown');
            } catch (GRPCExceptionInterface $e) {
                $statuses[] = $e;
            }
        }

        // The status is the wire outcome, so it was cached like a response: one run, identical answers —
        // and the exact subclass survives (every GRPCException shares one final constructor).
        Assert::same($calls, 1);
        Assert::true($statuses[1] instanceof OrderRejectedException);
        Assert::same($statuses[1]->getCode(), StatusCode::FAILED_PRECONDITION);
        Assert::same($statuses[1]->getMessage(), 'order already shipped');
        Assert::same($statuses[0]->getCode(), $statuses[1]->getCode());
        Assert::same($statuses[0]->getMessage(), $statuses[1]->getMessage());
    }

    public function statusDetailsSurviveTheReplay(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $call = $this->call(static function (): never {
            // Details carry the google.rpc.* error model — protobuf messages, snapshotted like a response.
            throw new GRPCException('invalid order', StatusCode::INVALID_ARGUMENT, [new FakeGrpcMessage('why')]);
        });

        try {
            $middleware->process($call, $this->through($driver));
        } catch (GRPCExceptionInterface) {
            // first attempt: cached
        }

        $thrown = null;
        try {
            $middleware->process($call, $this->through($driver));
        } catch (GRPCExceptionInterface $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        $details = $thrown->getDetails();
        Assert::same(\count($details), 1);
        Assert::true($details[0] instanceof FakeGrpcMessage);
        /** @var FakeGrpcMessage $detail */
        $detail = $details[0];
        Assert::same($detail->payload, 'why');
    }

    public function transientStatusIsNotCachedAndReRuns(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $calls = 0;
        $call = $this->call(static function () use (&$calls): never {
            ++$calls;
            throw new GRPCException('try later', StatusCode::UNAVAILABLE);
        });

        $codes = [];
        foreach (['first', 'second'] as $_) {
            try {
                $middleware->process($call, $this->through($driver));
            } catch (GRPCExceptionInterface $e) {
                $codes[] = $e->getCode();
            }
        }

        // UNAVAILABLE is transient (the gRPC counterpart of a 5xx): not cached, the key is released, so the
        // second attempt really re-runs instead of replaying a temporary failure for the retention TTL.
        Assert::same($calls, 2);
        Assert::same($codes, [StatusCode::UNAVAILABLE, StatusCode::UNAVAILABLE]);
    }

    public function mappedDomainFailureReplaysAsTheSameStatus(): void
    {
        $mapper = new DomainFailureMapperStub();
        $middleware = new GrpcOutcomeMiddleware(failures: $mapper);
        $driver = $this->driver();
        $calls = 0;
        // A plain domain exception: no GRPCException in sight, so only the mapper can turn it into a status.
        $call = $this->call(static function () use (&$calls): never {
            ++$calls;
            throw new PlainDeclineStub('card declined');
        });

        $codes = [];
        foreach (['first', 'replay'] as $_) {
            try {
                $middleware->process($call, $this->through($driver));
                Assert::fail('the mapped status must be rethrown');
            } catch (GRPCExceptionInterface $e) {
                $codes[] = $e->getCode();
            }
        }

        Assert::same($calls, 1);
        Assert::same($mapper->calls, 1);
        Assert::same($codes, [StatusCode::FAILED_PRECONDITION, StatusCode::FAILED_PRECONDITION]);
    }

    public function declinedMappingFallsBackToTheThrow(): void
    {
        // Mapper that owns nothing: behaviour must equal "no mapper bound at all".
        $mapper = new DomainFailureMapperStub(code: null);
        $middleware = new GrpcOutcomeMiddleware(failures: $mapper);
        $driver = $this->driver();
        $call = $this->call(static function (): never {
            throw new PlainDeclineStub('declined');
        });

        try {
            $middleware->process($call, $this->through($driver));
            Assert::fail('the declined failure must be rethrown');
        } catch (PlainDeclineStub) {
            // first attempt: the original type propagates (the RR server reports it as a worker error)
        }

        try {
            $middleware->process($call, $this->through($driver));
            Assert::fail('the cached domain failure must be rethrown on replay');
        } catch (CachedDomainFailureException $replayed) {
            // Documented fallback: the lightweight snapshot, mappable by originalClass.
            Assert::same($replayed->originalClass, PlainDeclineStub::class);
        }

        Assert::same($mapper->calls, 1);
    }

    public function retryableStatusIsNotSnapshotted(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $calls = 0;
        // A status the app marked Retryable → Infrastructure: the classifier gate must keep it a
        // throwable even though its code (FAILED_PRECONDITION) is a cacheable one.
        $call = $this->call(static function () use (&$calls): never {
            ++$calls;
            throw new RetryableStatusException('shard moving');
        });

        $codes = [];
        foreach (['first', 'second'] as $_) {
            try {
                $middleware->process($call, $this->through($driver));
            } catch (GRPCExceptionInterface $e) {
                $codes[] = $e->getCode();
            }
        }

        // Client still gets a proper status on both attempts, but nothing was cached: the key was freed.
        Assert::same($calls, 2);
        Assert::same($codes, [StatusCode::FAILED_PRECONDITION, StatusCode::FAILED_PRECONDITION]);
    }

    public function replayIsAnnouncedInTheResponseMetadata(): void
    {
        $headers = new ResponseHeaders();
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $call = $this->serviceCall(
            static fn(): mixed => new FakeGrpcMessage('pong'),
            $this->grpcContext($headers),
        );

        $middleware->process($call, $this->through($driver));
        Assert::same($headers->get('idempotency-replay'), 'false');

        $middleware->process($call, $this->through($driver));

        // The gRPC analog of Idempotency-Replay: the client can tell a replayed answer from a fresh one.
        Assert::same($headers->get('idempotency-replay'), 'true');
        // The key comes from the snapshot, so the marker does not depend on the middleware order.
        Assert::same($headers->get('idempotency-key'), 'k');
    }

    public function replayedStatusIsAlsoMarked(): void
    {
        $headers = new ResponseHeaders();
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $call = $this->serviceCall(
            static function (): never {
                throw new OrderRejectedException('order already shipped');
            },
            $this->grpcContext($headers),
        );

        foreach (['first', 'replay'] as $_) {
            try {
                $middleware->process($call, $this->through($driver));
            } catch (GRPCExceptionInterface) {
                // the status is the outcome; the marker must be set before it is rethrown
            }
        }

        // Server::serve() packs the header bag in its GRPCExceptionInterface branch too, so a replayed
        // failure is observable as a replay just like a replayed message.
        Assert::same($headers->get('idempotency-replay'), 'true');
        Assert::same($headers->get('idempotency-key'), 'k');
    }

    public function markingIsSkippedWithoutAHeaderBag(): void
    {
        // Non-gRPC stack (or a runtime that provides no header bag): stay inert, no error.
        $middleware = new GrpcOutcomeMiddleware();
        $driver = $this->driver();
        $call = $this->serviceCall(static fn(): mixed => new FakeGrpcMessage('pong'));

        $result = $middleware->process($call, $this->through($driver));

        Assert::true($result instanceof FakeGrpcMessage);
    }

    public function nullOutcomeBecomesTheDeclaredEmptyResponse(): void
    {
        // A fire-once duplicate (AtMostOnce) or a void operation resolves to null. The bridge's Invoker
        // requires a Message — `resultToString(Message $result)` — and its TypeError would surface as a
        // worker error, not a status. The declared response type is materialized instead.
        $middleware = new GrpcOutcomeMiddleware();
        $ctx = $this->context();
        $call = $this->serviceCall(static fn(): mixed => null);

        $result = $middleware->process($call, static fn(IdempotencyCall $c): mixed => ($c->operation)($ctx));

        Assert::true($result instanceof FakeGrpcMessage);
        /** @var FakeGrpcMessage $result */
        Assert::same($result->payload, '');
    }

    public function nullOutcomePassesThroughWithoutAServiceTarget(): void
    {
        // No reflectable target (non-gRPC stack, or a closure target): stay inert rather than inventing a
        // response out of thin air.
        $middleware = new GrpcOutcomeMiddleware();
        $ctx = $this->context();
        $call = $this->call(static fn(): mixed => null);

        $result = $middleware->process($call, static fn(IdempotencyCall $c): mixed => ($c->operation)($ctx));

        Assert::null($result);
    }

    public function foreignStatusCodeDegradesToUnknown(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static function (): never {
            throw new \LogicException('The operation must not run on a replay.');
        });

        // A row written by something else (or corrupted): the code is outside the gRPC 0..16 range and must
        // not reach the wire as-is. The exact class is also gone — the rebuild falls back to GRPCException.
        $next = static fn(IdempotencyCall $c): mixed => [
            '__idempotency_grpc_status__' => true,
            'class' => 'Vanished\\StatusException',
            'code' => 999,
            'message' => 'from another world',
            'details' => [],
        ];

        $thrown = null;
        try {
            $middleware->process($call, $next);
        } catch (GRPCExceptionInterface $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getCode(), StatusCode::UNKNOWN);
        Assert::same($thrown->getMessage(), 'from another world');
    }

    public function infrastructureFailureNeverReachesTheMapper(): void
    {
        $mapper = new DomainFailureMapperStub();
        $middleware = new GrpcOutcomeMiddleware(failures: $mapper);
        $driver = $this->driver();
        $calls = 0;
        $call = $this->call(static function () use (&$calls): never {
            ++$calls;
            throw new \Error('boom');
        });

        $thrown = 0;
        foreach (['first', 'second'] as $_) {
            try {
                $middleware->process($call, $this->through($driver));
            } catch (\Error) {
                ++$thrown;
            }
        }

        Assert::same($thrown, 2);
        // \Error is Infrastructure: never mapped, never cached — the key is released and the call re-runs.
        Assert::same($mapper->calls, 0);
        Assert::same($calls, 2);
    }
}
