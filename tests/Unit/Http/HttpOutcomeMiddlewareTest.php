<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\OutcomeUnknownException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Http\HttpOutcomeMiddleware;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Tests\Unit\Stub\StubIdempotencyContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HttpOutcomeMiddleware::class)]
final class HttpOutcomeMiddlewareTest
{
    private function middleware(): HttpOutcomeMiddleware
    {
        $psr17 = new Psr17Factory();

        return new HttpOutcomeMiddleware($psr17, $psr17);
    }

    /**
     * Runs the middleware around a driver handler that throws, as the interceptor terminal would.
     */
    private function answer(\Throwable $thrown): ResponseInterface
    {
        $call = new IdempotencyCall(
            context: new \stdClass(),
            operation: static fn(): mixed => null,
            options: new ExecuteOptions(),
        );

        $response = $this->middleware()->process($call, static function (IdempotencyCall $c) use ($thrown): never {
            throw $thrown;
        });
        \assert($response instanceof ResponseInterface);

        return $response;
    }

    public function outcomeUnknownIsAConflictWithoutRetryAfter(): void
    {
        $response = $this->answer(new OutcomeUnknownException('k', final: true));

        Assert::same($response->getStatusCode(), 409);
        // Unlike a lock, nothing tells when, or whether, an outcome gets stored.
        Assert::false($response->hasHeader('Retry-After'));
        Assert::same($response->getHeaderLine('Idempotency-Key'), 'k');
        Assert::same($response->getHeaderLine('Idempotency-Replay'), 'true');
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');

        $body = \json_decode((string) $response->getBody(), true);
        Assert::true(\is_array($body));
        /** @var array<string, mixed> $body */
        Assert::same($body['error'] ?? null, 'outcome_unknown');
    }

    public function outcomeUnknownOfANestedCallPropagates(): void
    {
        // Raised inside the action by a nested idempotent call: the action ran, this request is no duplicate.
        $nested = new OutcomeUnknownException('inner', final: true);
        $call = new IdempotencyCall(
            context: new \stdClass(),
            operation: static fn(): never => throw $nested,
            options: new ExecuteOptions(),
        );

        try {
            $this->middleware()->process(
                $call,
                static fn(IdempotencyCall $c): mixed => ($c->operation)(new StubIdempotencyContext()),
            );
            Assert::fail('a nested call\'s OutcomeUnknownException must not become a 409');
        } catch (OutcomeUnknownException $e) {
            Assert::same($e, $nested);
        }
    }

    public function nestedLockedIsAConflictUnderTheOwnKey(): void
    {
        // A nested lock released this request's own key: "409, retry after N" holds for it too, but the
        // inner key must not leak into this request's headers.
        $locked = new LockedException(new Locked('inner', 7, new \DateTimeImmutable()));
        $call = new IdempotencyCall(
            context: new \stdClass(),
            operation: static fn(): never => throw $locked,
            options: new ExecuteOptions(),
        );

        $response = $this->middleware()->process(
            $call,
            static fn(IdempotencyCall $c): mixed => ($c->operation)(new StubIdempotencyContext('outer')),
        );
        \assert($response instanceof ResponseInterface);

        Assert::same($response->getStatusCode(), 409);
        Assert::same($response->getHeaderLine('Retry-After'), '7');
        Assert::same($response->getHeaderLine('Idempotency-Key'), 'outer');
        Assert::string((string) $response->getBody())->contains('depends on');
    }

    public function lockedIsAConflictWithRetryAfter(): void
    {
        $response = $this->answer(new LockedException(new Locked('k', 7, new \DateTimeImmutable())));

        Assert::same($response->getStatusCode(), 409);
        Assert::same($response->getHeaderLine('Retry-After'), '7');
        Assert::same($response->getHeaderLine('Idempotency-Key'), 'k');
    }
}
