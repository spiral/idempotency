<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline\Middleware;

use Spiral\Idempotency\Exception\ClassifiedException;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\ExecutionMiddleware;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Tests\Unit\Stub\StubIdempotencyContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClassifierMiddleware::class)]
final class ClassifierMiddlewareTest
{
    public function passesSuccessThrough(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());

        $result = $middleware->process($this->call(), static fn(ExecutionCall $c): string => 'ok');

        Assert::same($result, 'ok');
    }

    public function classifiesPlainExceptionAsDomain(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());

        try {
            $middleware->process($this->call(), static fn(): mixed => throw new \RuntimeException('no funds'));
            Assert::fail('should throw ClassifiedException');
        } catch (ClassifiedException $e) {
            Assert::same($e->kind, FailureKind::Domain);
            Assert::same($e->getPrevious()?->getMessage(), 'no funds');
        }
    }

    public function classifiesErrorAsInfrastructure(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());

        try {
            $middleware->process($this->call(), static fn(): mixed => throw new \Error('transient'));
            Assert::fail('should throw ClassifiedException');
        } catch (ClassifiedException $e) {
            Assert::same($e->kind, FailureKind::Infrastructure);
        }
    }

    public function doesNotReclassifyAnAlreadyClassifiedException(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());
        $preset = new ClassifiedException(FailureKind::Bug, new \LogicException('marked'));

        try {
            $middleware->process($this->call(), static fn(): mixed => throw $preset);
            Assert::fail('should rethrow');
        } catch (ClassifiedException $e) {
            Assert::same($e, $preset);           // same instance, not re-wrapped
            Assert::same($e->kind, FailureKind::Bug);
        }
    }

    public function classifiedExceptionIsDetachedFromThePublicHierarchy(): void
    {
        $e = new ClassifiedException(FailureKind::Bug, new \LogicException('marked'));

        Assert::instanceOf($e, \RuntimeException::class);
        Assert::false($e instanceof IdempotencyException, 'must stay off the public exception hierarchy');
    }

    public function userMiddlewareDoesNotSwallowClassifiedMarking(): void
    {
        // A user execution middleware sitting between the classifier and the terminal, catching the
        // public IdempotencyException base type. It must NOT catch a ClassifiedException marker.
        $userMiddleware = new class implements ExecutionMiddleware {
            public function process(ExecutionCall $call, callable $next): mixed
            {
                try {
                    return $next($call);
                } catch (IdempotencyException) {
                    return 'swallowed';
                }
            }
        };

        $pipeline = new Pipeline(
            new ClassifierMiddleware(new DefaultFailureClassifier()),
            $userMiddleware,
        );

        // The terminal throws an already-classified marker (as an inner classifier would).
        $preset = new ClassifiedException(FailureKind::Bug, new \LogicException('marked'));

        try {
            $pipeline->process($this->call(), static fn(): mixed => throw $preset);
            Assert::fail('should rethrow ClassifiedException, not the swallowed value');
        } catch (ClassifiedException $e) {
            Assert::same($e, $preset);              // marking survives the user middleware
            Assert::same($e->kind, FailureKind::Bug);
        }
    }

    private function call(): ExecutionCall
    {
        return new ExecutionCall(
            context: new StubIdempotencyContext(),
            operation: static fn(): null => null,
            options: new ExecuteOptions(),
        );
    }
}
