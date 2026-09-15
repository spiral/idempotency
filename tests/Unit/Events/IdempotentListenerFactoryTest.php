<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Events;

use Spiral\Core\Container;
use Spiral\Events\AutowireListenerFactory;
use Spiral\Events\ListenerFactoryInterface;
use Spiral\Idempotency\ArgumentKeyResolver;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Events\HasIdempotencyKey;
use Spiral\Idempotency\Events\IdempotentListenerFactory;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\KeyResolver;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingIdempotency;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A durable event republished under a stable identity — the shape the outbox fan-out use case has.
 */
final class OrderPlaced implements HasIdempotencyKey
{
    public function __construct(
        public string $id,
        public string $payload = 'same-payload',
    ) {}

    public function idempotencyKey(): string
    {
        return $this->id;
    }
}

/**
 * No identity of its own: only an explicit `key` arg-path can make a listener of it idempotent.
 */
final class OrderShipped
{
    public function __construct(public string $orderId = 'o-1') {}
}

/**
 * The fan-out under test: several listener methods of one event, each with its own key space.
 */
final class OrderListeners
{
    /** @var list<string> */
    public array $runs = [];

    /** @var array<string, int> method => how many more calls must throw */
    public array $failures = [];

    public ?IdempotencyContext $seen = null;

    #[Idempotent(storage: 'events')]
    public function charge(object $event): void
    {
        $this->record('charge');
    }

    #[Idempotent(storage: 'events')]
    public function notify(object $event): void
    {
        $this->record('notify');
    }

    public function audit(object $event): void
    {
        $this->record('audit');
    }

    #[Idempotent(storage: 'events', key: 'event.orderId')]
    public function fromPath(object $event): void
    {
        $this->record('fromPath');
    }

    #[Idempotent(storage: 'events', key: 'event.missing')]
    public function fromBadPath(object $event): void
    {
        $this->record('fromBadPath');
    }

    // Both opt out of namespacing, so one event id is a single key shared by the two listeners.
    #[Idempotent(storage: 'events', scope: Idempotent::SCOPE_GLOBAL)]
    public function globalA(object $event): void
    {
        $this->record('globalA');
    }

    #[Idempotent(storage: 'events', scope: Idempotent::SCOPE_GLOBAL)]
    public function globalB(object $event): void
    {
        $this->record('globalB');
    }

    #[Idempotent(storage: 'recording')]
    public function recorded(object $event): void
    {
        $this->record('recorded');
    }

    #[Idempotent(storage: 'recording', lockTtl: 7, ttl: 900, failurePolicy: FailurePolicy::Cache)]
    public function configured(object $event): void
    {
        $this->record('configured');
    }

    #[Idempotent(storage: 'locked')]
    public function contended(object $event): void
    {
        $this->record('contended');
    }

    #[Idempotent(storage: 'events')]
    public function contextual(object $event, IdempotencyContext $context): void
    {
        $this->seen = $context;
        $this->record('contextual');
    }

    private function record(string $method): void
    {
        $this->runs[] = $method;

        if (($this->failures[$method] ?? 0) > 0) {
            $this->failures[$method]--;

            throw new \RuntimeException($method . ' failed');
        }
    }
}

#[Idempotent(storage: 'events')]
final class MarkedListenerClass
{
    /** @var list<string> */
    public array $runs = [];

    public function onEvent(object $event): void
    {
        $this->runs[] = 'onEvent';
    }
}

/**
 * Always contended: the storage a {@see LockedException} comes out of.
 */
final class LockedIdempotency implements Idempotency
{
    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        throw new LockedException(new Locked($key, retryAfter: 5, expireTime: new \DateTimeImmutable()));
    }
}

#[Test]
#[Covers(IdempotentListenerFactory::class)]
final class IdempotentListenerFactoryTest
{
    private Container $container;
    private RecordingIdempotency $recorder;

    /**
     * A storage per test: the lease records and the recorder's log must not leak between scenarios that
     * dispatch the same event ids.
     */
    #[BeforeTest]
    public function setUpStorages(): void
    {
        $clock = new MutableClock();

        $registry = new IdempotencyRegistry();
        $registry->register(
            'events',
            new LeaseIdempotency(
                new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock),
                new Pipeline(),
                lockTtl: 30,
                retentionTtl: 3600,
            ),
            Guarantee::AtLeastOnce,
        );
        $this->recorder = new RecordingIdempotency();
        $registry->register('recording', $this->recorder, Guarantee::AtLeastOnce);
        $registry->register('locked', new LockedIdempotency(), Guarantee::AtLeastOnce);

        $this->container = new Container();
        $this->container->bindSingleton(IdempotencyRegistry::class, $registry);
        $this->container->bindSingleton(KeyResolver::class, new DefaultKeyResolver());
        $this->container->bindSingleton(ArgumentKeyResolver::class, new DefaultArgumentKeyResolver());
    }

    public function failingListenerRerunsAloneOnTheNextDispatch(): void
    {
        $listeners = new OrderListeners();
        $listeners->failures['notify'] = 1;
        $fanOut = $this->fanOut($listeners, 'charge', 'notify');
        $event = new OrderPlaced('evt-1');

        // A PSR-14 dispatcher stops the fan-out at the first exception, so the dispatch surfaces it.
        try {
            $this->dispatch($fanOut, $event);
            Assert::fail('The failing listener must not be swallowed.');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'notify failed');
        }

        $this->dispatch($fanOut, $event);

        Assert::same($listeners->runs, ['charge', 'notify', 'notify']);
    }

    public function unmarkedListenerRunsOnEveryDispatch(): void
    {
        $listeners = new OrderListeners();
        $fanOut = $this->fanOut($listeners, 'charge', 'audit');
        $event = new OrderPlaced('evt-2');

        $this->dispatch($fanOut, $event);
        $this->dispatch($fanOut, $event);

        Assert::same($listeners->runs, ['charge', 'audit', 'audit']);
    }

    public function unmarkedListenerKeepsTheInnerClosure(): void
    {
        $closure = static function (object $event): void {};
        $inner = new class($closure) implements ListenerFactoryInterface {
            public function __construct(private readonly \Closure $closure) {}

            public function create(string|object $listener, string $method): \Closure
            {
                return $this->closure;
            }
        };

        $factory = new IdempotentListenerFactory($inner, $this->container);

        Assert::same($factory->create(OrderListeners::class, 'audit'), $closure);
        Assert::notSame($factory->create(OrderListeners::class, 'charge'), $closure);
    }

    public function distinctEventsWithEqualPayloadRunTheListenerOnceEach(): void
    {
        $listeners = new OrderListeners();
        $fanOut = $this->fanOut($listeners, 'charge');

        $this->dispatch($fanOut, new OrderPlaced('evt-3a'));
        $this->dispatch($fanOut, new OrderPlaced('evt-3b'));
        $this->dispatch($fanOut, new OrderPlaced('evt-3a'));

        Assert::same($listeners->runs, ['charge', 'charge']);
    }

    public function eachListenerOfOneEventOwnsItsKeySpace(): void
    {
        // One event id reaches both listeners: the default scope (ListenerClass::method) is what keeps
        // the second from replaying the first one's record.
        $listeners = new OrderListeners();
        $fanOut = $this->fanOut($listeners, 'charge', 'notify');

        $this->dispatch($fanOut, new OrderPlaced('evt-4'));

        Assert::same($listeners->runs, ['charge', 'notify']);
    }

    public function globalScopeCollapsesTheListenersIntoOneKeySpace(): void
    {
        $listeners = new OrderListeners();
        $fanOut = $this->fanOut($listeners, 'globalA', 'globalB');

        $this->dispatch($fanOut, new OrderPlaced('evt-5'));

        Assert::same($listeners->runs, ['globalA']);
    }

    public function keyComesFromTheAttributePathOverTheEvent(): void
    {
        $listeners = new OrderListeners();
        $fanOut = $this->fanOut($listeners, 'fromPath');

        $this->dispatch($fanOut, new OrderShipped('o-7'));
        $this->dispatch($fanOut, new OrderShipped('o-7'));
        $this->dispatch($fanOut, new OrderShipped('o-8'));

        Assert::same($listeners->runs, ['fromPath', 'fromPath']);
    }

    public function eventWithNoKeySourceFailsWithAClearException(): never
    {
        $fanOut = $this->fanOut(new OrderListeners(), 'charge');

        Expect::exception(MissingKeyException::class)->withMessageContaining(HasIdempotencyKey::class);

        $this->dispatch($fanOut, new OrderShipped());
    }

    public function unresolvableKeyPathIsAMisconfiguration(): never
    {
        $fanOut = $this->fanOut(new OrderListeners(), 'fromBadPath');

        Expect::exception(MisconfigurationException::class)->withMessageContaining('event.missing');

        $this->dispatch($fanOut, new OrderShipped());
    }

    public function classLevelAttributeCoversTheListenerMethod(): void
    {
        $listener = new MarkedListenerClass();
        $fanOut = $this->fanOut($listener, 'onEvent');
        $event = new OrderPlaced('evt-9');

        $this->dispatch($fanOut, $event);
        $this->dispatch($fanOut, $event);

        Assert::same($listener->runs, ['onEvent']);
    }

    public function attributeOptionsReachTheStorage(): void
    {
        $this->dispatch($this->fanOut(new OrderListeners(), 'configured'), new OrderPlaced('evt-10'));

        $options = $this->recorder->calls[0]->options;
        Assert::same($options?->lockTtl, 7);
        Assert::same($options?->ttl, 900);
        Assert::same($options?->failurePolicy, FailurePolicy::Cache);
    }

    public function defaultFailurePolicyReleasesTheKey(): void
    {
        // The durable producer redelivers the event, so a failing listener must leave its key free
        // rather than turn the failure into a replayable negative outcome.
        $this->dispatch($this->fanOut(new OrderListeners(), 'recorded'), new OrderPlaced('evt-11'));

        Assert::same($this->recorder->calls[0]->options?->failurePolicy, FailurePolicy::Release);
    }

    public function keyIsNamespacedByTheListenerIdentity(): void
    {
        $this->dispatch($this->fanOut(new OrderListeners(), 'recorded'), new OrderPlaced('evt-14'));

        Assert::same($this->recorder->keys(), [OrderListeners::class . '::recorded:evt-14']);
    }

    public function lockedPropagatesUntouched(): never
    {
        $fanOut = $this->fanOut(new OrderListeners(), 'contended');

        Expect::exception(LockedException::class);

        $this->dispatch($fanOut, new OrderPlaced('evt-12'));
    }

    public function listenerCanInjectTheIdempotencyContext(): void
    {
        $listeners = new OrderListeners();
        $this->container->bindSingleton(OrderListeners::class, $listeners);

        // Two parameters, so the inner factory autowires the call — the context comes from the child
        // scope the decorator opens around it.
        $fanOut = $this->fanOut(OrderListeners::class, 'contextual');

        $this->dispatch($fanOut, new OrderPlaced('evt-13'));

        Assert::instanceOf($listeners->seen, IdempotencyContext::class);
        Assert::same($listeners->seen?->getKey(), OrderListeners::class . '::contextual:evt-13');
    }

    /**
     * Build the listener closures the way the `AttributeProcessor` does — one per method, in fan-out
     * order.
     *
     * @param class-string|object $listener
     * @return list<\Closure(object): void>
     */
    private function fanOut(string|object $listener, string ...$methods): array
    {
        $factory = new IdempotentListenerFactory(new AutowireListenerFactory(), $this->container);

        return \array_map(
            static fn(string $method): \Closure => $factory->create($listener, $method),
            $methods,
        );
    }

    /**
     * One PSR-14 dispatch: the closures run in order and the first exception stops the fan-out.
     *
     * @param list<\Closure(object): void> $listeners
     */
    private function dispatch(array $listeners, object $event): void
    {
        foreach ($listeners as $listener) {
            $listener($event);
        }
    }
}
