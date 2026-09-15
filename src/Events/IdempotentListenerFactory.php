<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Events;

use Psr\Container\ContainerInterface;
use Spiral\Core\Container;
use Spiral\Core\ContainerScope;
use Spiral\Core\Scope;
use Spiral\Events\ListenerFactoryInterface;
use Spiral\Idempotency\ArgumentKeyResolver;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\IdempotentLocator;
use Spiral\Idempotency\KeyResolver;

/**
 * Makes PSR-14 event listeners idempotent declaratively via {@see Idempotent}, by decorating the
 * factory that builds one closure per listener class + method.
 *
 * The factory — not a decorating `EventDispatcherInterface` — is the integration point: the dispatcher
 * only ever sees an opaque list of closures, while the factory is the single place that still knows
 * WHICH listener a closure belongs to. That identity is what makes a per-listener key possible, so a
 * fan-out where one listener of five throws re-runs only that one on the next dispatch instead of
 * replaying all five.
 *
 * A method without the attribute gets the inner factory's closure unchanged — the decorator adds no
 * indirection to the listeners it does not cover.
 *
 * Key material, in order:
 *
 *  1. the attribute's `key` arg-path, resolved over `['event' => $event]` — so the path starts with
 *     `event`, e.g. `key: 'event.id'`;
 *  2. otherwise {@see HasIdempotencyKey::idempotencyKey()} on the event.
 *
 * Neither available is an error: deduplicating on nothing would silently disable the guarantee.
 *
 * The scope (`ListenerClass::method` by default) is mixed in as the resolver's parent key, so every
 * listener of one event owns its own key space and one event id does not collide across them.
 *
 * A {@see \Spiral\Idempotency\Exception\LockedException} propagates untouched: the PSR-14 dispatcher
 * stops the fan-out at the first exception, so the surrounding redelivery — the durable producer that
 * dispatched the event — is what retries a concurrent duplicate.
 *
 * @api
 */
final readonly class IdempotentListenerFactory implements ListenerFactoryInterface
{
    /**
     * @param ListenerFactoryInterface $factory inner factory building the actual listener closure —
     *        `AutowireListenerFactory` in a stock application
     * @param ContainerInterface $container read per dispatch, never at construction: the factory runs
     *        during bootstrap, before the idempotency registry is assembled
     * @param FailurePolicy $failurePolicy what a failing listener does to its key when the attribute
     *        states no policy; the default frees it so the next delivery of the event re-runs that
     *        listener
     */
    public function __construct(
        private ListenerFactoryInterface $factory,
        private ContainerInterface $container,
        private FailurePolicy $failurePolicy = FailurePolicy::Release,
    ) {}

    public function create(string|object $listener, string $method): \Closure
    {
        $inner = $this->factory->create($listener, $method);
        $attribute = $this->attribute($listener, $method);

        if ($attribute === null) {
            return $inner;
        }

        $scope = $attribute->scope ?? $this->listenerId($listener, $method);
        $scope = $scope === Idempotent::SCOPE_GLOBAL ? null : $scope;

        return function (object $event) use ($inner, $attribute, $scope): void {
            $registry = $this->container->get(IdempotencyRegistry::class);
            \assert($registry instanceof IdempotencyRegistry);

            $registry->get($attribute->storage)->execute(
                $this->key($attribute, $event, $scope),
                fn(IdempotencyContext $context): null => $this->run($inner, $event, $context),
                new ExecuteOptions(
                    $attribute->lockTtl,
                    $attribute->ttl,
                    $attribute->failurePolicy ?? $this->failurePolicy,
                ),
            );
        };
    }

    /**
     * Run the listener with the active {@see IdempotencyContext} bound, so it can be injected into the
     * listener (and narrowed to a driver contract such as
     * {@see \Spiral\Idempotency\Driver\Cycle\CycleContext}, whose connection an ExactlyOnce side-effect
     * must be written through).
     *
     * The binding lives in a fresh nested container destroyed on return — no mutation of the (possibly
     * root) container, no cross-dispatch leak. A container without scope support runs the listener
     * without the binding.
     *
     * @param \Closure(object): void $inner
     */
    private function run(\Closure $inner, object $event, IdempotencyContext $context): null
    {
        $container = ContainerScope::getContainer() ?? $this->container;
        if (!$container instanceof Container) {
            $inner($event);

            return null;
        }

        $container->runScope(
            new Scope(bindings: [IdempotencyContext::class => $context]),
            static fn(): mixed => $inner($event),
        );

        return null;
    }

    /**
     * @param non-empty-string|null $scope listener identity mixed in as the resolver's parent key
     * @return non-empty-string
     */
    private function key(Idempotent $attribute, object $event, ?string $scope): string
    {
        $keys = $this->container->get(KeyResolver::class);
        \assert($keys instanceof KeyResolver);

        if ($attribute->key === null) {
            return $keys->resolve(
                $event instanceof HasIdempotencyKey ? $event->idempotencyKey() : throw new MissingKeyException(
                    \sprintf(
                        'Event %s carries no idempotency key: it does not implement %s, and the '
                        . '#[Idempotent] attribute of the listener states no `key` path.',
                        $event::class,
                        HasIdempotencyKey::class,
                    ),
                ),
                $scope,
            );
        }

        $arguments = $this->container->get(ArgumentKeyResolver::class);
        \assert($arguments instanceof ArgumentKeyResolver);

        // An explicit path is a contract ("the key is here"): a typo must fail rather than fall back to
        // HasIdempotencyKey and deduplicate on a different basis than the author intended.
        $raw = $arguments->resolve(['event' => $event], $attribute->key);

        return $raw !== null ? $keys->resolve($raw, $scope) : throw new MisconfigurationException(
            \sprintf('Idempotency key path "%s" resolved to nothing on event %s.', $attribute->key, $event::class),
            'The `key` arg-path of #[Idempotent] on a listener is resolved over the single argument '
            . '`event`, so it starts with that name — e.g. `key: \'event.id\'`. Point it at a value with '
            . 'a string form (scalar, Stringable or backed enum), or drop it and let the event implement '
            . HasIdempotencyKey::class . '.',
        );
    }

    /**
     * @param class-string|object $listener
     */
    private function attribute(string|object $listener, string $method): ?Idempotent
    {
        $class = \is_object($listener) ? $listener::class : $listener;

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            // The inner factory accepts a listener it cannot reflect (an unresolved container alias) and
            // autowires it at dispatch time; there is no attribute to read for one.
            return null;
        }

        return IdempotentLocator::locate($reflection, \class_exists($class) ? $class : null);
    }

    /**
     * Default key scope: the listener identity the dispatcher has otherwise lost.
     *
     * @param class-string|object $listener
     * @return non-empty-string
     */
    private function listenerId(string|object $listener, string $method): string
    {
        return (\is_object($listener) ? $listener::class : $listener) . '::' . $method;
    }
}
