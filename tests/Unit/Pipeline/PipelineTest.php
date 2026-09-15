<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\ExecutionMiddleware;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Idempotency\Tests\Unit\Stub\StubIdempotencyContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Pipeline::class)]
final class PipelineTest
{
    public function runsTerminalWhenNoMiddleware(): void
    {
        $pipeline = new Pipeline();

        $result = $pipeline->process($this->call(), static fn(IdempotencyCall $c): string => 'terminal');

        Assert::same($result, 'terminal');
    }

    public function executesMiddlewareOuterToInner(): void
    {
        // Each middleware wraps the rest, so the first listed is the outermost: A( B( core ) ).
        $pipeline = new Pipeline($this->wrapping('A'), $this->wrapping('B'));

        $result = $pipeline->process($this->call(), static fn(IdempotencyCall $c): string => 'core');

        Assert::same($result, 'A(B(core))');
    }

    public function middlewareCanShortCircuit(): void
    {
        $reached = false;
        $shortCircuit = new class implements ResolutionMiddleware {
            public function process(IdempotencyCall $call, callable $next): mixed
            {
                return 'cached'; // never calls $next
            }
        };

        $pipeline = new Pipeline($shortCircuit);
        $result = $pipeline->process(
            $this->call(),
            static function () use (&$reached): string {
                $reached = true;
                return 'core';
            },
        );

        Assert::same($result, 'cached');
        Assert::false($reached);
    }

    public function middlewareCanEnrichTheCall(): void
    {
        $enrich = new class implements ResolutionMiddleware {
            public function process(IdempotencyCall $call, callable $next): mixed
            {
                return $next($call->withKey('resolved'));
            }
        };

        $pipeline = new Pipeline($enrich);
        $result = $pipeline->process($this->call(), static fn(IdempotencyCall $c): ?string => $c->key);

        Assert::same($result, 'resolved');
    }

    public function pipelineIsReusableAcrossCalls(): void
    {
        // process() must not mutate the shared instance (position is reset per run).
        $pipeline = new Pipeline($this->wrapping('A'));

        $first = $pipeline->process($this->call(), static fn(IdempotencyCall $c): string => '1');
        $second = $pipeline->process($this->call(), static fn(IdempotencyCall $c): string => '2');

        Assert::same($first, 'A(1)');
        Assert::same($second, 'A(2)');
    }

    public function sameEngineDrivesTheExecutionPhase(): void
    {
        // The one universal engine also runs ExecutionMiddleware over ExecutionCall — different context,
        // same matryoshka. Terminal calls the business operation with the driver-built context.
        $tap = new class implements ExecutionMiddleware {
            public function process(ExecutionCall $call, callable $next): mixed
            {
                return 'wrapped:' . $next($call);
            }
        };

        $pipeline = new Pipeline($tap);
        $call = new ExecutionCall(
            context: $this->executionContext(),
            operation: static fn(IdempotencyContext $c): string => 'done:' . $c->getKey(),
            options: new ExecuteOptions(),
        );

        $result = $pipeline->process($call, static fn(ExecutionCall $c): mixed => ($c->operation)($c->context));

        Assert::same($result, 'wrapped:done:k');
    }

    private function wrapping(string $name): ResolutionMiddleware
    {
        return new class ($name) implements ResolutionMiddleware {
            public function __construct(private readonly string $name) {}

            public function process(IdempotencyCall $call, callable $next): mixed
            {
                return $this->name . '(' . $next($call) . ')';
            }
        };
    }

    private function call(): IdempotencyCall
    {
        return new IdempotencyCall(
            context: null,
            operation: static fn(): null => null,
            options: new ExecuteOptions(),
        );
    }

    private function executionContext(): IdempotencyContext
    {
        return new StubIdempotencyContext();
    }
}
