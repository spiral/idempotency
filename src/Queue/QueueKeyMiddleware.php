<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Queue;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\KeyResolver;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\CallContextInterface;

/**
 * Queue resolution middleware: extracts the raw key from the job headers carried in the call context
 * (the `Idempotency-Key` header) and normalizes it via the shared {@see KeyResolver}. The
 * queue analog of {@see \Spiral\Idempotency\Http\HttpKeyMiddleware}.
 *
 * On the consume side, Spiral's queue `Handler` builds the {@see CallContextInterface} as
 * `new CallContext(Target, ['driver', 'queue', 'id', 'payload', 'headers'])` — so the job headers travel
 * as a call ARGUMENT (`getArguments()['headers']`), NOT as a context attribute. They are the PSR-7-like
 * shape `array<string, list<string>>`. No scope binding is needed; add this middleware to the queue
 * pipeline (`transports.queue`) in config instead.
 *
 * The producer sets the header on the outbound job, e.g. `Options::withHeader('Idempotency-Key', ...)`.
 * Producers outside the application — a CDC pipeline reading a transactional outbox table, say — set no
 * headers at all; for them `$fallbackToJobId` derives the key from the broker job id instead (the `id`
 * argument of the same consume call context). That id identifies one broker MESSAGE, not the logical
 * operation: it is the same when the broker redelivers the message (consumer crash, missing ack), while a
 * job re-published by a retry policy or pushed a second time by the producer carries a new one — whether
 * a driver preserves the id across its own requeue is driver-specific. Deduplicating the operation itself
 * still needs a key from the payload or the header. The fallback stays off by default so that a producer
 * that merely forgot the header does not get silent, weaker deduplication.
 *
 * Pass-through when the key is already resolved (e.g. from the attribute's arg path, which for jobs
 * reads the payload — `key: 'payload.<field>'`) or when the context is not a queue call context
 * (type-guard, so it is inert in a non-queue stack). Uses only `spiral/interceptors` types — never
 * `spiral/queue` — so it carries no hard dependency on the queue package.
 *
 * @api
 */
final readonly class QueueKeyMiddleware implements ResolutionMiddleware
{
    /**
     * @param non-empty-string $header job header carrying the key
     * @param bool $fallbackToJobId use the broker job id as key material when the header is absent
     */
    public function __construct(
        private KeyResolver $resolver,
        private string $header = 'Idempotency-Key',
        private bool $fallbackToJobId = false,
    ) {}

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        if ($call->key !== null) {
            return $next($call);
        }

        // The transport object is the consume call context; without its arguments there are no job
        // headers to read, so stay inert (a non-queue stack).
        if (!$call->context instanceof CallContextInterface) {
            return $next($call);
        }

        $arguments = $call->context->getArguments();
        $raw = $this->fromHeaders($arguments) ?? $this->fromJobId($arguments);

        if ($raw === null) {
            throw new MissingKeyException($this->fallbackToJobId
                ? \sprintf(
                    'Idempotency key is required: neither the "%s" job header nor the broker job id '
                    . 'yielded one.',
                    $this->header,
                )
                : \sprintf(
                    'Idempotency key is required: set the "%s" job header (producer side, e.g. '
                    . 'Options::withHeader), or enable the job-id fallback of %s.',
                    $this->header,
                    self::class,
                ));
        }

        // The scope (operation identity by default) travels on the call; the transport stays agnostic of
        // its format and just hands it to the resolver as the parent key so job types do not collide.
        return $next($call->withKey($this->resolver->resolve($raw, $call->keyScope)));
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return non-empty-string|null
     */
    private function fromHeaders(array $arguments): ?string
    {
        $headers = $arguments['headers'] ?? [];
        if (!\is_array($headers) || !isset($headers[$this->header][0]) || !\is_scalar($headers[$this->header][0])) {
            return null;
        }

        $raw = (string) $headers[$this->header][0];

        return $raw === '' ? null : $raw;
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return non-empty-string|null
     */
    private function fromJobId(array $arguments): ?string
    {
        if (!$this->fallbackToJobId || !isset($arguments['id']) || !\is_scalar($arguments['id'])) {
            return null;
        }

        $raw = (string) $arguments['id'];

        return $raw === '' ? null : $raw;
    }
}
