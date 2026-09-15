<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline;

use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Retryable;
use Spiral\Queue\Exception\RetryableExceptionInterface;
use Spiral\Queue\RetryPolicyInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DefaultFailureClassifier::class)]
final class DefaultFailureClassifierTest
{
    public function errorIsInfrastructureByDefault(): void
    {
        Assert::same((new DefaultFailureClassifier())->classify(new \Error('x')), FailureKind::Infrastructure);
    }

    public function retryableExceptionIsInfrastructure(): void
    {
        $e = new class ('x') extends \Exception implements Retryable {};

        Assert::same((new DefaultFailureClassifier())->classify($e), FailureKind::Infrastructure);
    }

    public function queueContractIsAvailableInTheTestEnvironment(): void
    {
        // The three tests below only exercise the new rules while the classifier's `interface_exists()`
        // guard is satisfied; without `spiral/queue` in the dev deps they would pass for the wrong reason.
        Assert::true(\interface_exists(RetryableExceptionInterface::class));
    }

    public function retryableQueueExceptionIsInfrastructure(): void
    {
        $classifier = new DefaultFailureClassifier();

        Assert::same($classifier->classify(new QueueFailure(retryable: true)), FailureKind::Infrastructure);
    }

    public function nonRetryableQueueExceptionIsDomain(): void
    {
        $classifier = new DefaultFailureClassifier();

        Assert::same($classifier->classify(new QueueFailure(retryable: false)), FailureKind::Domain);
    }

    public function queueContractOutranksTheErrorRule(): void
    {
        // The single point where an existing app can see a different kind than before 0.4: an \Error
        // used to be Infrastructure unconditionally, and a non-retryable one is now Domain.
        $classifier = new DefaultFailureClassifier();

        Assert::same($classifier->classify(new QueueError(retryable: false)), FailureKind::Domain);
        Assert::same($classifier->classify(new QueueError(retryable: true)), FailureKind::Infrastructure);
    }

    public function bugMarkingTakesPrecedenceOverTheQueueContract(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [QueueFailure::class]);

        Assert::same($classifier->classify(new QueueFailure(retryable: true)), FailureKind::Bug);
    }

    public function plainExceptionIsDomain(): void
    {
        Assert::same((new DefaultFailureClassifier())->classify(new \RuntimeException('x')), FailureKind::Domain);
    }

    public function explicitlyMarkedExceptionIsBug(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [\LogicException::class]);

        Assert::same($classifier->classify(new \LogicException('x')), FailureKind::Bug);
    }

    public function bugMarkingTakesPrecedenceOverErrorDefault(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [\TypeError::class]);

        Assert::same($classifier->classify(new \TypeError('x')), FailureKind::Bug);
    }
}

/**
 * A job failure that declares its own retry intent the way `spiral/queue` users do — the shape the
 * classifier must honour instead of demanding a second, idempotency-specific marker.
 */
final class QueueFailure extends \RuntimeException implements RetryableExceptionInterface
{
    public function __construct(private readonly bool $retryable)
    {
        parent::__construct('x');
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getRetryPolicy(): ?RetryPolicyInterface
    {
        return null;
    }
}

/** The exotic overlap the {@see DefaultFailureClassifierTest::queueContractOutranksTheErrorRule()} case pins. */
final class QueueError extends \Error implements RetryableExceptionInterface
{
    public function __construct(private readonly bool $retryable)
    {
        parent::__construct('x');
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getRetryPolicy(): ?RetryPolicyInterface
    {
        return null;
    }
}
