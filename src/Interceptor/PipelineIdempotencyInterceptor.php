<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Interceptor;

use Psr\Container\ContainerInterface;
use Spiral\Core\Container;
use Spiral\Core\ContainerScope;
use Spiral\Core\Scope;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\KeyResolver;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\Context\TargetInterface;
use Spiral\Interceptors\HandlerInterface;

/**
 * Makes controller/handler actions idempotent declaratively via {@see Idempotent}.
 *
 * Thin, transport-agnostic entry point into the config-driven pipeline: it reads the attribute, builds
 * an {@see IdempotencyCall} (the whole call context as {@see IdempotencyCall::$context}), assembles this
 * transport's resolution stack from config, and runs it around the storage handler
 * (`registry->get(storage)->execute(...)`).
 *
 * Everything transport-specific lives in the resolution middleware (HTTP key extraction, response
 * marshalling, Locked→409). One instance per transport, parameterized by {@see $transport} — the same
 * class serves HTTP, Queue, Events by pointing at a different config stack.
 *
 * The attribute's `key` arg-path (dot-notation over the call arguments) is resolved here, since it is
 * transport-independent and needs the attribute; a null result lets a transport middleware supply the
 * key instead.
 *
 * @api
 */
final class PipelineIdempotencyInterceptor implements IdempotencyInterceptor
{
    /**
     * This transport's resolution stack, assembled once and reused: the middleware are stateless
     * `readonly` services and {@see Pipeline::process()} is reusable by contract, so a single instance
     * per interceptor (itself a per-transport singleton) is safe.
     *
     * @var Pipeline<IdempotencyCall>|null
     */
    private ?Pipeline $pipeline = null;

    /**
     * Reflected attributes memoised by operation identity, avoiding a reflection scan per call. Keyed by
     * the *concrete* `Class::method` — the lookup walks the class hierarchy, so two subclasses sharing
     * one inherited method resolve to different attributes and must not share an entry. Only method
     * targets are keyed; other targets (closures) are looked up each time.
     *
     * @var array<string, Idempotent|null>
     */
    private array $attributes = [];

    /**
     * @param non-empty-string $transport config key of this transport's resolution stack
     */
    public function __construct(
        private readonly IdempotencyRegistry $registry,
        private readonly KeyResolver $keys,
        private readonly ContainerInterface $container,
        private readonly IdempotencyConfig $config,
        private readonly string $transport,
    ) {}

    public function intercept(CallContextInterface $context, HandlerInterface $handler): mixed
    {
        $attribute = $this->attribute($context->getTarget());

        if ($attribute === null) {
            return $handler->handle($context);
        }

        $storage = $attribute->storage;

        // Namespace the key by operation identity (Class::method) unless the attribute overrides it, so the
        // same client key on two different endpoints sharing a storage alias does not cross-replay. An
        // explicit scope name shares one space deliberately; SCOPE_GLOBAL ('') opts out into a single space.
        $scope = $attribute->scope ?? $this->targetId($context);
        $scope = $scope === Idempotent::SCOPE_GLOBAL ? null : $scope;

        $call = new IdempotencyCall(
            context: $context,
            // Expose the driver's context (e.g. a CycleContext with the transactional connection) so the
            // action can inject IdempotencyContext and narrow to it.
            operation: fn(IdempotencyContext $operation): mixed => $this->dispatch($operation, $handler, $context),
            options: new ExecuteOptions($attribute->lockTtl, $attribute->ttl),
            key: $this->keyFromArguments($attribute, $context, $scope),
            keyScope: $scope,
        );

        return ($this->pipeline ??= $this->buildPipeline())->process(
            $call,
            fn(IdempotencyCall $c): mixed => $this->registry->get($storage)->execute(
                $c->key ?? throw new MissingKeyException(
                    'No idempotency key was resolved by the attribute arg-path nor by any transport middleware.',
                ),
                $c->operation,
                $c->options,
            ),
        );
    }

    /**
     * Assemble this transport's resolution stack from config, resolving each middleware via the container.
     *
     * @return Pipeline<IdempotencyCall>
     */
    private function buildPipeline(): Pipeline
    {
        $middleware = [];
        foreach ($this->config->getTransport($this->transport) as $class) {
            $instance = $this->container->get($class);
            \assert($instance instanceof ResolutionMiddleware);
            $middleware[] = $instance;
        }

        /** @var Pipeline<IdempotencyCall> */
        return new Pipeline(...$middleware);
    }

    /**
     * Resolve the key from the attribute's arg-path (dot-notation over the call arguments). A null result
     * is returned only for `key: null` — letting a transport middleware extract the key instead. An
     * explicit `key` path is a contract ("the key is here"): failing to resolve it is a misconfiguration
     * (typo in the path, or a non-scalar value), so we fail fast rather than silently falling back to the
     * transport and deduplicating on a different basis than the author intended.
     *
     * @param non-empty-string|null $scope operation-identity namespace mixed in as the resolver's parent key
     * @return non-empty-string|null
     * @throws MisconfigurationException when an explicit `key` path resolves to nothing
     */
    private function keyFromArguments(Idempotent $attribute, CallContextInterface $context, ?string $scope): ?string
    {
        if ($attribute->key === null) {
            return null;
        }

        $raw = $this->dotGet($context->getArguments(), $attribute->key);

        return $raw !== null ? $this->keys->resolve($raw, $scope) : throw new MisconfigurationException(
            \sprintf(
                'Idempotency key path "%s" resolved to nothing for %s; available top-level arguments: %s.',
                $attribute->key,
                (string) $context->getTarget(),
                \implode(', ', \array_keys($context->getArguments())) ?: '(none)',
            ),
            'Point the `key` arg-path of #[Idempotent] at an existing scalar argument (dot-notation '
            . 'over the call arguments), or set `key: null` to let a transport middleware supply the '
            . 'key (e.g. the Idempotency-Key header).',
        );
    }

    /**
     * Run the action with the active {@see IdempotencyContext} bound, so it can be injected into the
     * controller (and narrowed to a driver contract such as
     * {@see \Spiral\Idempotency\Driver\Cycle\CycleContext}).
     *
     * Uses an isolated child scope: the binding lives only in a fresh nested container that is destroyed
     * on return — no mutation of the (possibly root) container, no cross-request leak, async-safe. If the
     * active container has no scope support (e.g. a bare PSR container), the action runs without the
     * binding.
     */
    private function dispatch(
        IdempotencyContext $operation,
        HandlerInterface $handler,
        CallContextInterface $context,
    ): mixed {
        $container = ContainerScope::getContainer() ?? $this->container;
        if (!$container instanceof Container) {
            return $handler->handle($context);
        }

        return $container->runScope(
            new Scope(bindings: [IdempotencyContext::class => $operation]),
            fn(): mixed => $handler->handle($context),
        );
    }

    /**
     * Operation identity used as the default key scope: `Class::method` of the concrete target, falling
     * back to the target's string form when reflection is not a method (e.g. a closure target). Sibling
     * subclasses sharing one inherited entry point therefore get one key space each.
     *
     * @return non-empty-string
     */
    private function targetId(CallContextInterface $context): string
    {
        $target = $context->getTarget();
        $reflection = $target->getReflection();
        if ($reflection instanceof \ReflectionMethod) {
            return $this->operationId($target, $reflection);
        }

        $id = (string) $target;

        return $id === '' ? 'unknown' : $id;
    }

    /**
     * Resolve the attribute for a target: the method first, then the class hierarchy of the concrete
     * target from the most derived class up. A method attribute wins over a class one, a subclass over
     * its parents.
     *
     * @param TargetInterface<object|null> $target
     */
    private function attribute(TargetInterface $target): ?Idempotent
    {
        $reflection = $target->getReflection();
        if ($reflection === null) {
            return null;
        }

        // Cache by operation identity for method targets (the common case); non-method targets
        // (e.g. closures) have no stable key, so they resolve the attribute afresh each time.
        $cacheKey = $reflection instanceof \ReflectionMethod ? $this->operationId($target, $reflection) : null;

        if ($cacheKey !== null && \array_key_exists($cacheKey, $this->attributes)) {
            return $this->attributes[$cacheKey];
        }

        $resolved = $this->fromReflection($reflection);

        if ($resolved === null && $reflection instanceof \ReflectionMethod) {
            $class = new \ReflectionClass($this->concreteClass($target) ?? $reflection->getDeclaringClass()->name);
            do {
                $resolved = $this->fromReflection($class);
            } while ($resolved === null && ($class = $class->getParentClass()) !== false);
        }

        if ($cacheKey !== null) {
            $this->attributes[$cacheKey] = $resolved;
        }

        return $resolved;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionFunctionAbstract $reflection
     */
    private function fromReflection(\ReflectionClass|\ReflectionFunctionAbstract $reflection): ?Idempotent
    {
        foreach ($reflection->getAttributes(Idempotent::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $instance = $attribute->newInstance();
            \assert($instance instanceof Idempotent);

            return $instance;
        }

        return null;
    }

    /**
     * @param TargetInterface<object|null> $target
     * @return non-empty-string
     */
    private function operationId(TargetInterface $target, \ReflectionMethod $reflection): string
    {
        return ($this->concreteClass($target) ?? $reflection->getDeclaringClass()->name) . '::' . $reflection->getName();
    }

    /**
     * The class the target was built from, which {@see \ReflectionMethod::getDeclaringClass()} does not
     * give for an inherited method: the bound object if the target carries one, else the first path
     * segment when it names a real class.
     *
     * @param TargetInterface<object|null> $target
     * @return class-string|null
     */
    private function concreteClass(TargetInterface $target): ?string
    {
        $object = $target->getObject();
        if ($object !== null) {
            return $object::class;
        }

        $candidate = $target->getPath()[0] ?? null;

        return \is_string($candidate) && $candidate !== '' && \class_exists($candidate) ? $candidate : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param string $path dot-notation path
     */
    private function dotGet(array $data, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $cursor = $data;
        foreach (\explode('.', $path) as $segment) {
            if (\is_array($cursor) && \array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }
            if (\is_object($cursor) && isset($cursor->{$segment})) {
                $cursor = $cursor->{$segment};
                continue;
            }
            return null;
        }

        return \is_scalar($cursor) ? (string) $cursor : null;
    }
}
