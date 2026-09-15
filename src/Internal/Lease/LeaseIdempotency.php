<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\ClassifiedException;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Internal\SystemClock;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseManager;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\FailureClassifier;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\ReplayableFailure;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * The AtLeastOnce lease handler (the boundary between the two pipelines, spec-pipeline §0): it owns
 * `acquire` + the guaranteed terminal transition, and runs the operation through the
 * {@see $execution} pipeline (classify / retry / ... ) in between.
 *
 * Failure classification lives in the execution pipeline (a {@see \Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware}),
 * which rethrows a {@see ClassifiedException} carrying the {@see FailureKind}; this handler reads that
 * kind to pick the terminal transition. When no classifier ran (bare throwable), it falls back to its
 * own {@see $classifier}, so the handler stays correct with or without that middleware. An
 * {@see ExecuteOptions::$failurePolicy} of {@see FailurePolicy::Release} overrides all of it: the key is
 * freed on any failure and the original throwable is rethrown.
 *
 * Losing the lease at the terminal transition is an observable event, NOT an outcome of the operation.
 * The operation has already run; its result (or its throwable) belongs to the caller. So when a terminal
 * transition is CAS-rejected (the lock TTL expired mid-flight and the key was re-acquired), this handler
 * downgrades the {@see LeaseLostException} raised by the manager to a logger warning (via {@see terminal()})
 * and still returns the value / rethrows the original throwable — it only skips caching the outcome, since
 * the record now belongs to the new owner. The strict "throw on lost lease" contract stays in the
 * {@see \Spiral\Idempotency\Lease\LeaseManager} for the future renewal middleware. Until that
 * middleware exists, a handler MUST finish within its lock TTL; a lost lease means the TTL is too short.
 *
 * @internal Built per storage alias by the factory; consumers resolve {@see Idempotency}
 *           from the {@see \Spiral\Idempotency\IdempotencyRegistry}. Not part of the public API.
 */
final readonly class LeaseIdempotency implements Idempotency, GuaranteeProvider
{
    private SerializerInterface $serializer;
    private FailureClassifier $classifier;
    private ClockInterface $clock;

    /**
     * @param Pipeline<ExecutionCall> $execution wraps the operation (classify / retry / renewal / ...)
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds (short)
     * @param int<1, max> $retentionTtl COMPLETED retention TTL, seconds (long)
     * @param positive-int $acquireRetryLimit bound on AcquireRetry loops
     * @param float $heartbeatThreshold fraction of lockTtl an unforced heartbeat waits before it renews
     *        again — throttles {@see \Spiral\Idempotency\IdempotencyContext::renew()} (see {@see HeartbeatThrottle})
     */
    public function __construct(
        private LeaseManager $manager,
        private Pipeline $execution,
        private int $lockTtl = 30,
        private int $retentionTtl = 86400,
        ?SerializerInterface $serializer = null,
        ?FailureClassifier $classifier = null,
        private int $acquireRetryLimit = 3,
        private ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
        private float $heartbeatThreshold = 0.5,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
        $this->classifier = $classifier ?? new DefaultFailureClassifier();
        $this->clock = $clock ?? new SystemClock();
    }

    public function guarantee(): Guarantee
    {
        return Guarantee::AtLeastOnce;
    }

    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        $options ??= new ExecuteOptions();
        /** @var int<1, max> $lockTtl */
        $lockTtl = $options->lockTtl ?? $this->lockTtl;
        /** @var int<1, max> $retentionTtl */
        $retentionTtl = $options->ttl ?? $this->retentionTtl;

        $attempts = 0;
        do {
            $result = $this->manager->acquire($key, $lockTtl);

            if ($result instanceof Acquired) {
                return $this->run($result, $operation, $options, $retentionTtl, $lockTtl);
            }

            if ($result instanceof AlreadyCompleted) {
                return $this->replay($result);
            }

            if ($result instanceof Locked) {
                throw new LockedException($result);
            }

            // AcquireRetry — loop and try acquire again.
        } while (++$attempts < $this->acquireRetryLimit);

        throw new IdempotencyException(\sprintf(
            'acquire() kept resolving to AcquireRetry for key "%s" after %d attempts.',
            $key,
            $attempts,
        ));
    }

    /**
     * @param int<1, max> $retentionTtl
     * @param int<1, max> $lockTtl
     */
    private function run(
        Acquired $lease,
        \Closure $operation,
        ExecuteOptions $options,
        int $retentionTtl,
        int $lockTtl,
    ): mixed {
        // Per-execution heartbeat: keeps this PROCESSING lock alive when the operation renews at safe
        // points (directly via renew(), or via Fiber::suspend() under FiberRenewalMiddleware). Throttled
        // so unforced beats cannot hammer the storage.
        $throttle = new HeartbeatThrottle(
            $this->clock,
            function () use ($lease, $lockTtl): void {
                $this->manager->renew($lease->key, $lease->token, $lockTtl);
            },
            $lockTtl,
            $this->heartbeatThreshold,
            $lease->key,
            $this->logger,
        );

        $call = new ExecutionCall(new LeaseContext($lease->key, $throttle(...)), $operation, $options);

        try {
            $value = $this->execution->process(
                $call,
                static fn(ExecutionCall $c): mixed => ($c->operation)($c->context),
            );
        } catch (\Throwable $e) {
            $this->terminateFailure($lease, $e, $retentionTtl, $options->failurePolicy ?? FailurePolicy::Cache);
            // Surface the original throwable, not the ClassifiedException wrapper.
            throw $e instanceof ClassifiedException ? ($e->getPrevious() ?? $e) : $e;
        }

        if ($value instanceof Uncacheable) {
            // Transient outcome (e.g. 5xx): don't cache, release the key so a retry re-runs.
            $this->terminal(function () use ($lease): void {
                $this->manager->abort($lease->key, $lease->token);
            }, $lease->key);

            return $value->value;
        }

        $this->terminal(function () use ($lease, $value, $retentionTtl): void {
            $this->manager->complete($lease->key, $lease->token, true, $this->encode($value), $retentionTtl);
        }, $lease->key);

        return $value;
    }

    /**
     * @param int<1, max> $retentionTtl
     * @param FailurePolicy $policy {@see FailurePolicy::Release} short-circuits the classification: any
     *        failure frees the key so the next call re-runs the operation.
     */
    private function terminateFailure(
        Acquired $lease,
        \Throwable $e,
        int $retentionTtl,
        FailurePolicy $policy,
    ): void {
        if ($policy === FailurePolicy::Release) {
            // The caller owns the retry: free the key on any failure, never classify. The throwable
            // itself is rethrown by run(), unchanged.
            $this->terminal(function () use ($lease): void {
                $this->manager->abort($lease->key, $lease->token);
            }, $lease->key);

            return;
        }

        // The kind comes from the classifier middleware (via ClassifiedException); fall back to our own
        // classifier when the operation threw a bare throwable (no classifier middleware in the stack).
        $original = $e instanceof ClassifiedException ? ($e->getPrevious() ?? $e) : $e;
        $kind = $e instanceof ClassifiedException ? $e->kind : $this->classifier->classify($e);

        $this->terminal(function () use ($kind, $lease, $original, $retentionTtl): void {
            match ($kind) {
                // Domain: a valid (negative) outcome — cache a lightweight snapshot for idempotent replay.
                FailureKind::Domain => $this->manager->complete(
                    $lease->key,
                    $lease->token,
                    false,
                    $this->encodeFailure($original),
                    $retentionTtl,
                ),
                // Bug: unrecoverable, do not re-enqueue; report happens outside.
                FailureKind::Bug => $this->manager->error($lease->key, $lease->token),
                // Infrastructure: free the key, the transport/client retries.
                FailureKind::Infrastructure => $this->manager->abort($lease->key, $lease->token),
            };
        }, $lease->key);
    }

    /**
     * Run a terminal transition, downgrading a lost lease to a warning: the operation's outcome belongs
     * to the caller; the loss itself only signals the lock TTL is too short (and the outcome is not cached,
     * since the record now belongs to the new owner).
     */
    private function terminal(\Closure $transition, string $key): void
    {
        try {
            $transition();
        } catch (LeaseLostException $e) {
            $this->logger?->warning(
                'Idempotency lease for key "{key}" was lost before the terminal transition; '
                . 'the operation outcome is preserved, but consider a longer lockTtl.',
                ['key' => $key, 'exception' => $e],
            );
        }
    }

    private function replay(AlreadyCompleted $completed): mixed
    {
        $decoded = $this->decode(\is_string($completed->result) ? $completed->result : null);

        if ($completed->success) {
            return $decoded;
        }

        // Cached domain failure — rethrow a deterministic snapshot of the original outcome.
        if (\is_array($decoded) && isset($decoded['class'], $decoded['message'])) {
            $class = (string) $decoded['class'];

            // Faithful replay: the original exception opted in via ReplayableFailure AND its
            // class still exists (is_a() with a vanished class returns false — deploy-safe, no fatal).
            if (isset($decoded['payload']) && \is_a($class, ReplayableFailure::class, true)) {
                throw $class::fromReplayPayload((array) $decoded['payload']);
            }

            // Fallback: rethrow the lightweight snapshot; the app maps by originalClass in its handler.
            throw new CachedDomainFailureException($class, (string) $decoded['message']);
        }

        throw new IdempotencyException(\sprintf(
            'Cached domain failure for key "%s" is missing its snapshot.',
            $completed->key,
        ));
    }

    private function encode(mixed $value): ?string
    {
        // FireOnce / void result — no serialization.
        return $value === null ? null : (string) $this->serializer->serialize($value);
    }

    /**
     * Lightweight, always-serializable snapshot of a domain failure (class + message), avoiding the
     * fragility of serializing the throwable object itself (its trace may capture closures). When the
     * failure opts into {@see ReplayableFailure}, its JSON-safe payload is stored too, so
     * {@see replay()} can rethrow the exact same type instead of a {@see CachedDomainFailureException}.
     */
    private function encodeFailure(\Throwable $e): string
    {
        $snapshot = ['class' => $e::class, 'message' => $e->getMessage()];
        if ($e instanceof ReplayableFailure) {
            $snapshot['payload'] = $e->toReplayPayload();
        }

        return (string) $this->serializer->serialize($snapshot);
    }

    private function decode(?string $blob): mixed
    {
        return $blob === null ? null : $this->serializer->unserialize($blob);
    }
}
