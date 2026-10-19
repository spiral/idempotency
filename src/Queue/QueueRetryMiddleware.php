<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Queue;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\OutcomeUnknownException;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;

/**
 * Queue outcome middleware: maps the idempotent call's failure modes back to the transport. The queue
 * analog of {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}, but jobs return void, so there is NO
 * response marshalling — only failure mapping.
 *
 *  - turns a {@see LockedException} (someone else holds PROCESSING for this key) into a
 *    {@see RetryableLockException}, so the job is re-enqueued after the lock TTL rather than failing;
 *  - lets a {@see \Spiral\Idempotency\Exception\MissingKeyException} propagate untouched: a job that can
 *    never resolve a key is not retryable and should dead-letter instead of retrying forever;
 *  - turns an {@see OutcomeUnknownException} (an AtMostOnce duplicate with nothing to replay) into a
 *    `null` result, so the consumer ACKs the job (fire-once semantics) — it never reaches Spiral's retry
 *    policy, which would otherwise fail or re-enqueue a job that must not run again;
 *  - passes the handler's return value through untouched.
 *
 * ORDERING: Spiral's default-on `Spiral\Queue\Interceptor\Consume\RetryPolicyInterceptor` must sit
 * OUTSIDE this interceptor in the `interceptors.consume` list, so it catches the re-thrown
 * {@see RetryableLockException}, resolves its {@see RetryableLockException::getRetryPolicy()} and
 * re-queues the job with the carried delay. We do not reimplement any of that machinery here.
 *
 * @api
 */
final readonly class QueueRetryMiddleware implements ResolutionMiddleware
{
    /**
     * @param int<0, max> $maxLockRetries retry budget for lock contention, handed to the native retry policy
     */
    public function __construct(
        private int $maxLockRetries = 10,
    ) {}

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        try {
            return $next($call);
        } catch (LockedException $e) {
            // Someone else holds PROCESSING for this key. Re-enqueue after the lock is expected to clear,
            // rather than failing the job. The native RetryPolicyInterceptor (registered OUTSIDE this
            // interceptor) catches this and re-queues with the carried delay.
            throw new RetryableLockException($e->lock->retryAfter, $this->maxLockRetries, $e);
        } catch (OutcomeUnknownException) {
            return null;
        }
    }
}
