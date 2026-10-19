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
        $response = $this->answer(new OutcomeUnknownException('k'));

        Assert::same($response->getStatusCode(), 409);
        // Retrying the same key never yields a different answer, so there is nothing to wait for.
        Assert::false($response->hasHeader('Retry-After'));
        Assert::same($response->getHeaderLine('Idempotency-Key'), 'k');
        Assert::same($response->getHeaderLine('Idempotency-Replay'), 'true');
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');

        $body = \json_decode((string) $response->getBody(), true);
        Assert::true(\is_array($body));
        /** @var array<string, mixed> $body */
        Assert::same($body['error'] ?? null, 'outcome_unknown');
    }

    public function lockedIsAConflictWithRetryAfter(): void
    {
        $response = $this->answer(new LockedException(new Locked('k', 7, new \DateTimeImmutable())));

        Assert::same($response->getStatusCode(), 409);
        Assert::same($response->getHeaderLine('Retry-After'), '7');
        Assert::same($response->getHeaderLine('Idempotency-Key'), 'k');
    }
}
