<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Interceptor;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
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

/**
 * The shape the feature exists for: one entry point implemented once on an abstract base, which every
 * job subclass inherits without redeclaring (Spiral's queue dispatches `Target::fromPair($name, 'handle')`,
 * so the reflection's declaring class is this base, never the concrete job).
 */
abstract class InheritedEntryPointJob
{
    public function handle(string $id): string
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the result.');
    }
}

#[Idempotent(storage: 'jobs', key: 'id')]
final class AnnotatedSubclassJob extends InheritedEntryPointJob {}

#[Idempotent(storage: 'jobs', key: 'id')]
final class SecondAnnotatedSubclassJob extends InheritedEntryPointJob {}

/** Shares the inherited `handle()` with the annotated siblings, but carries no attribute of its own. */
final class PlainSubclassJob extends InheritedEntryPointJob {}

#[Idempotent(storage: 'jobs', key: 'id', lockTtl: 42)]
abstract class AnnotatedBaseJob
{
    public function handle(string $id): string
    {
        throw new \LogicException('Not invoked directly.');
    }
}

final class InheritsBaseAttributeJob extends AnnotatedBaseJob {}

#[Idempotent(storage: 'nearest', key: 'id')]
final class OverridesBaseAttributeJob extends AnnotatedBaseJob {}

#[Idempotent(storage: 'class-level', key: 'id')]
final class MethodOverridesClassJob
{
    #[Idempotent(storage: 'method-level', key: 'id')]
    public function handle(string $id): string
    {
        throw new \LogicException('Not invoked directly.');
    }
}

#[Test]
#[Covers(PipelineIdempotencyInterceptor::class)]
#[Covers(Idempotent::class)]
final class ClassLevelIdempotentTest
{
    /**
     * One double per storage alias, so which alias the interceptor resolved is an assertion about which
     * double was run.
     *
     * @var array<non-empty-string, RecordingIdempotency>
     */
    private array $storages = [];

    /**
     * An interceptor over an empty resolution stack: the attribute's arg-path supplies the key, so no
     * transport middleware is involved and the doubles see exactly what the attribute resolved to.
     */
    private function interceptor(): PipelineIdempotencyInterceptor
    {
        $registry = new IdempotencyRegistry();
        $this->storages = [];
        foreach (['jobs', 'nearest', 'class-level', 'method-level'] as $alias) {
            $this->storages[$alias] = new RecordingIdempotency();
            $registry->register($alias, $this->storages[$alias], Guarantee::AtLeastOnce);
        }

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

        $config = new IdempotencyConfig([
            'default' => 'jobs',
            'storages' => [],
            'transports' => ['queue' => []],
        ]);

        return new PipelineIdempotencyInterceptor(
            $registry,
            new DefaultKeyResolver(),
            new DefaultArgumentKeyResolver(),
            $container,
            $config,
            'queue',
        );
    }

    /**
     * @param class-string $job
     */
    private function context(string $job, string $id = 'job-1'): CallContext
    {
        // Exactly how spiral/queue dispatches a job: a class-string pair, no bound object, so the
        // reflection's declaring class is the abstract base and only the target path names the job.
        return new CallContext(Target::fromPair($job, 'handle'), ['id' => $id]);
    }

    public function classAttributeOnSubclassWithInheritedMethodIsHonoured(): void
    {
        $handler = new RecordingHandler();

        $result = $this->interceptor()->intercept($this->context(AnnotatedSubclassJob::class), $handler);

        Assert::same($result, 'run#1');
        Assert::same(\count($handler->calls), 1);
        // Default scope is the CONCRETE class, not the base that declares handle().
        Assert::same($this->storages['jobs']->keys(), [AnnotatedSubclassJob::class . '::handle:job-1']);
    }

    public function targetBoundToAnInstanceResolvesTheClassAttributeToo(): void
    {
        $context = new CallContext(
            Target::fromPair(new AnnotatedSubclassJob(), 'handle'),
            ['id' => 'job-2'],
        );

        $this->interceptor()->intercept($context, new RecordingHandler());

        Assert::same($this->storages['jobs']->keys(), [AnnotatedSubclassJob::class . '::handle:job-2']);
    }

    /**
     * The sibling shares the inherited `handle()` — and therefore the declaring class — with the annotated
     * jobs. It must stay unintercepted even when an annotated sibling primed the attribute cache first.
     */
    public function unannotatedSiblingSharingTheInheritedMethodIsNotIntercepted(): void
    {
        $interceptor = $this->interceptor();
        $handler = new RecordingHandler();

        $interceptor->intercept($this->context(AnnotatedSubclassJob::class), $handler);
        $result = $interceptor->intercept($this->context(PlainSubclassJob::class), $handler);

        Assert::same($result, 'run#2');
        Assert::same(\count($handler->calls), 2);
        Assert::same($this->storages['jobs']->keys(), [AnnotatedSubclassJob::class . '::handle:job-1']);
    }

    /**
     * Two annotated siblings sharing one inherited entry point and one storage alias: the default scope
     * keeps them in separate key spaces, so the same job id does not cross-replay.
     */
    public function annotatedSiblingsGetDistinctDefaultScopes(): void
    {
        $interceptor = $this->interceptor();
        $handler = new RecordingHandler();

        $interceptor->intercept($this->context(AnnotatedSubclassJob::class, 'same-id'), $handler);
        $interceptor->intercept($this->context(SecondAnnotatedSubclassJob::class, 'same-id'), $handler);

        Assert::same($this->storages['jobs']->keys(), [
            AnnotatedSubclassJob::class . '::handle:same-id',
            SecondAnnotatedSubclassJob::class . '::handle:same-id',
        ]);
    }

    public function methodAttributeWinsOverClassAttribute(): void
    {
        $this->interceptor()->intercept($this->context(MethodOverridesClassJob::class), new RecordingHandler());

        Assert::same(\count($this->storages['method-level']->calls), 1);
        Assert::same($this->storages['class-level']->calls, []);
    }

    public function classAttributeIsInheritedFromAnAnnotatedBase(): void
    {
        $this->interceptor()->intercept($this->context(InheritsBaseAttributeJob::class), new RecordingHandler());

        Assert::same($this->storages['jobs']->keys(), [InheritsBaseAttributeJob::class . '::handle:job-1']);
        Assert::same($this->storages['jobs']->calls[0]->options?->lockTtl, 42);
    }

    public function nearestClassInTheHierarchyWinsOverTheAnnotatedBase(): void
    {
        $this->interceptor()->intercept($this->context(OverridesBaseAttributeJob::class), new RecordingHandler());

        Assert::same($this->storages['jobs']->calls, []);
        Assert::same(\count($this->storages['nearest']->calls), 1);
        // The base's lockTtl does not bleed through — the nearest attribute is taken whole.
        Assert::same($this->storages['nearest']->calls[0]->options?->lockTtl, null);
    }
}
