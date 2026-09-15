<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Pipeline;

use Spiral\Idempotency\Pipeline\FailureClassifier;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Retryable;
use Spiral\Queue\Exception\RetryableExceptionInterface;

/**
 * Default, replaceable classifier:
 *
 *   RetryableExceptionInterface        → Infrastructure when `isRetryable()`, Domain otherwise
 *   \Error (and subclasses)            → Infrastructure (retry: a redeploy between attempts may fix it)
 *   \Exception + Retryable    → Infrastructure
 *   \Exception (everything else)       → Domain      ⚠ caches un-tagged infra for the retention TTL
 *
 * Bug is never inferred from a type — it is only an explicit user decision. Pass the set
 * of class-strings that must be treated as Bug, or override this classifier entirely.
 *
 * @internal Bound to {@see FailureClassifier} by the bootloader; not part of the public API.
 */
final class DefaultFailureClassifier implements FailureClassifier
{
    /**
     * Resolved once: `interface_exists()` runs the autoloader, and with `spiral/queue` absent that is a
     * failed file lookup Composer would repeat on every classified failure.
     */
    private readonly bool $queueContract;

    /**
     * @param list<class-string<\Throwable>> $bugExceptions exceptions the user declares unrecoverable
     */
    public function __construct(
        private readonly array $bugExceptions = [],
    ) {
        $this->queueContract = \interface_exists(RetryableExceptionInterface::class);
    }

    public function classify(\Throwable $e): FailureKind
    {
        foreach ($this->bugExceptions as $bug) {
            if ($e instanceof $bug) {
                return FailureKind::Bug;
            }
        }

        // `spiral/queue` is a `require-dev` + `suggest`, so the contract is only reachable behind the
        // guard — without the package the classifier falls through to the rules below unchanged. An app
        // that already states its retry intent through the queue's own contract must not have to restate
        // it as {@see Retryable}; a non-retryable one is a settled negative outcome, hence Domain. The
        // check precedes the \Error rule so that an explicit contract outranks the throwable's type.
        if ($this->queueContract && $e instanceof RetryableExceptionInterface) {
            return $e->isRetryable() ? FailureKind::Infrastructure : FailureKind::Domain;
        }

        if ($e instanceof \Error) {
            return FailureKind::Infrastructure;
        }

        if ($e instanceof Retryable) {
            return FailureKind::Infrastructure;
        }

        return FailureKind::Domain;
    }
}
