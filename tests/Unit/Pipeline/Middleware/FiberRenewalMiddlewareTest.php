<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline\Middleware;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\Middleware\FiberRenewalMiddleware;
use Spiral\Idempotency\Tests\Unit\Stub\RecordingIdempotencyContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FiberRenewalMiddleware::class)]
final class FiberRenewalMiddlewareTest
{
    private function context(): RecordingIdempotencyContext
    {
        return new RecordingIdempotencyContext();
    }

    public function firesHeartbeatOnEachSuspend(): void
    {
        $ctx = $this->context();
        $operation = function (IdempotencyContext $c): string {
            for ($i = 0; $i < 3; $i++) {
                \Fiber::suspend();
            }
            return 'done';
        };
        $next = static fn(ExecutionCall $c): mixed => ($c->operation)($c->context);
        $call = new ExecutionCall($ctx, $operation, new ExecuteOptions());

        $result = (new FiberRenewalMiddleware())->process($call, $next);

        Assert::same($result, 'done');
        Assert::same(\count($ctx->renewals), 3);
    }

    public function noSuspendPassesThroughWithZeroBeats(): void
    {
        $ctx = $this->context();
        $operation = static fn(IdempotencyContext $c): string => 'x';
        $next = static fn(ExecutionCall $c): mixed => ($c->operation)($c->context);
        $call = new ExecutionCall($ctx, $operation, new ExecuteOptions());

        $result = (new FiberRenewalMiddleware())->process($call, $next);

        Assert::same($result, 'x');
        Assert::same(\count($ctx->renewals), 0);
    }

    public function exceptionPropagatesAfterHeartbeat(): void
    {
        $ctx = $this->context();
        $operation = static function (IdempotencyContext $c): never {
            \Fiber::suspend();
            throw new \RuntimeException('boom');
        };
        $next = static fn(ExecutionCall $c): mixed => ($c->operation)($c->context);
        $call = new ExecutionCall($ctx, $operation, new ExecuteOptions());

        $caught = null;
        try {
            (new FiberRenewalMiddleware())->process($call, $next);
            Assert::fail('should rethrow the RuntimeException');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'boom');
        Assert::same(\count($ctx->renewals), 1);
    }

    public function transparentUnderOuterFiber(): void
    {
        $ctx = $this->context();
        $seen = [];
        $operation = static function (IdempotencyContext $c) use (&$seen): array {
            $seen[] = \Fiber::suspend();
            $seen[] = \Fiber::suspend();
            return $seen;
        };
        $next = static fn(ExecutionCall $c): mixed => ($c->operation)($c->context);
        $call = new ExecutionCall($ctx, $operation, new ExecuteOptions());

        $outer = new \Fiber(fn(): mixed => (new FiberRenewalMiddleware())->process($call, $next));
        $outer->start();
        $token = 0;
        while (!$outer->isTerminated()) {
            $outer->resume('tok' . (++$token));
        }
        $ret = $outer->getReturn();

        Assert::same($ret, ['tok1', 'tok2']);
        Assert::same(\count($ctx->renewals), 2);
    }

    /**
     * When the middleware itself runs inside an outer fiber and the scheduler THROWS into it (e.g. a
     * cancellation), the exception must be re-injected into the operation at its suspend point — not
     * abandon the inner fiber. Here the operation catches it and recovers.
     */
    public function forwardsInjectedExceptionIntoOperation(): void
    {
        $ctx = $this->context();
        $seen = [];
        $operation = static function (IdempotencyContext $c) use (&$seen): string {
            try {
                \Fiber::suspend();
            } catch (\RuntimeException $e) {
                $seen[] = 'caught:' . $e->getMessage();
            }
            \Fiber::suspend();

            return 'recovered';
        };
        $next = static fn(ExecutionCall $c): mixed => ($c->operation)($c->context);
        $call = new ExecutionCall($ctx, $operation, new ExecuteOptions());

        $outer = new \Fiber(fn(): mixed => (new FiberRenewalMiddleware())->process($call, $next));
        $outer->start();                                 // -> inner suspend #1, proxied up
        $outer->throw(new \RuntimeException('cancel'));   // injected -> forwarded into the operation
        $outer->resume();                                // resume inner suspend #2
        $ret = $outer->getReturn();

        Assert::same($ret, 'recovered');
        Assert::same($seen, ['caught:cancel']);
        Assert::same(\count($ctx->renewals), 2);
    }

    /**
     * An injected exception the operation does NOT catch surfaces out of the middleware (and the inner
     * fiber is terminated), rather than being swallowed or leaking a suspended fiber.
     */
    public function propagatesUncaughtInjectedException(): void
    {
        $ctx = $this->context();
        $operation = static function (IdempotencyContext $c): string {
            \Fiber::suspend();

            return 'unreachable';
        };
        $next = static fn(ExecutionCall $c): mixed => ($c->operation)($c->context);
        $call = new ExecutionCall($ctx, $operation, new ExecuteOptions());

        $outer = new \Fiber(fn(): mixed => (new FiberRenewalMiddleware())->process($call, $next));
        $outer->start();

        $caught = null;
        try {
            $outer->throw(new \LogicException('boom'));
            Assert::fail('the uncaught injected exception must surface');
        } catch (\LogicException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'boom');
        Assert::true($outer->isTerminated());
    }
}
