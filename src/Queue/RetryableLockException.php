<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Queue;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Queue\Exception\RetryableExceptionInterface;
use Spiral\Queue\RetryPolicy;
use Spiral\Queue\RetryPolicyInterface;

/**
 * Adapts a concurrency {@see LockedException} (someone else holds PROCESSING for this key) into Spiral's
 * retryable-exception contract, so the native `RetryPolicyInterceptor` re-enqueues the job after the
 * lock TTL instead of dead-lettering it. This is the queue analog of the HTTP `409 Conflict` +
 * `Retry-After` response produced by {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}.
 *
 * The decoupling invariant (the transport package is a `require-dev` + `suggest`, never forced on the
 * core) holds because this class is only ever loaded from the queue transport. The one other place
 * that names a `spiral/queue` type, {@see \Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier},
 * guards it with `interface_exists()` instead, because it runs on every transport.
 *
 * @api
 */
final class RetryableLockException extends \RuntimeException implements RetryableExceptionInterface
{
    /**
     * @param int<0, max> $delay suggested seconds until the lock is expected to clear (from the lock's `retryAfter`)
     * @param int<0, max> $maxAttempts retry budget handed to the native retry policy
     */
    public function __construct(
        private readonly int $delay,
        private readonly int $maxAttempts,
        LockedException $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function getRetryPolicy(): ?RetryPolicyInterface
    {
        // Clamp the delay to >= 1 so a `retryAfter` of 0 does not spin a hot retry loop. Constant delay
        // (multiplier 1.0) because a lock TTL is fixed, not exponentially growing.
        return new RetryPolicy(maxAttempts: $this->maxAttempts, delay: \max(1, $this->delay), multiplier: 1.0);
    }
}
