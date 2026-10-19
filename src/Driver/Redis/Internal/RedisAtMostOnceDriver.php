<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Psr\Log\LoggerInterface;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Internal\AtMostOnceContext;
use Spiral\Idempotency\Lease\TokenFactory;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * AtMostOnce (dedup-guard) driver over a Redis-compatible server — the Redis counterpart of
 * {@see \Spiral\Idempotency\Driver\Cycle\Internal\CycleAtMostOnceDriver}:
 *
 *   1. `SET {keyPrefix}{key} <marker> NX EX <ttl>` claims the key before the effect (the point of no
 *      return);
 *   2. on a fresh claim the effect runs fire-and-forget — a crash or a throwable here loses the effect,
 *      the marker persists, so a retry is *refused* (not re-run). The effect therefore runs 0 or 1 times;
 *   3. a duplicate finds the key taken and is refused: it returns `null` (fire-once) or, with
 *      {@see $cacheResult} on, the best-effort cached result.
 *
 * The marker value is `M:<token>`; the optional result cache overwrites it with `R:<serialized result>`
 * after the effect, keeping the remaining TTL. That write is a CAS on the marker: if the key expired
 * mid-operation and another caller claimed it, the stale result does not overwrite their marker.
 *
 * Both commands go through {@see RedisCommands::eval()}, so any existing adapter can back this driver.
 *
 * @internal Built per storage alias by the factory; consumers resolve the configured storage from the
 *           {@see \Spiral\Idempotency\IdempotencyRegistry}. Not part of the public API.
 */
final readonly class RedisAtMostOnceDriver implements Idempotency, GuaranteeProvider
{
    private const string MARKER = 'M:';
    private const string RESULT = 'R:';

    /**
     * Claims the key; replies `{1}` on a fresh claim, otherwise `{0}` — or `{0, <stored value>}` when
     * ARGV[3] asks for the cached result. In Lua a failed `SET ... NX` is `false`.
     */
    private const string CLAIM = <<<'LUA'
        if redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then return {1} end
        if ARGV[3] == '1' then return {0, redis.call('GET', KEYS[1])} end
        return {0}
        LUA;

    /**
     * Replaces the caller's own marker with the result, keeping the remaining TTL. `PTTL` + `SET PX`
     * rather than `SET ... XX KEEPTTL`, which needs Redis 6.0.
     */
    private const string STORE = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
        local ttl = redis.call('PTTL', KEYS[1])
        if ttl <= 0 then return 0 end
        redis.call('SET', KEYS[1], ARGV[2], 'PX', ttl)
        return 1
        LUA;

    private SerializerInterface $serializer;

    /**
     * @param non-empty-string $keyPrefix
     * @param int<1, max> $retentionTtl dedup window (the marker TTL), seconds
     * @param bool $cacheResult best-effort store + replay of the operation result (default off: a
     *        duplicate gets `null`)
     */
    public function __construct(
        private RedisCommands $client,
        private TokenFactory $tokens,
        private string $keyPrefix = 'idempotency-amo:',
        private int $retentionTtl = 86400,
        ?SerializerInterface $serializer = null,
        private bool $cacheResult = false,
        private ?LoggerInterface $logger = null,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    public function guarantee(): Guarantee
    {
        return Guarantee::AtMostOnce;
    }

    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        // $options->lockTtl is meaningless here (no lock), and $options->failurePolicy is ignored on
        // purpose: releasing the key after a failure would let the effect run twice, which is the one
        // thing this guarantee forbids. $options->ttl narrows or widens the dedup window per operation.
        /** @var non-empty-string $key */
        $redisKey = $this->keyPrefix . $key;
        $marker = self::MARKER . $this->tokens->create();
        // `int<1, max>` is a docblock promise only. A clamp to one second would quietly turn the guard off, so
        // a `ttl: 0` meant as "no expiry" fails before the claim and the effect never runs.
        /** @var int|null $operationTtl */
        $operationTtl = $options?->ttl;
        if ($operationTtl !== null && $operationTtl < 1) {
            throw new MisconfigurationException(
                \sprintf('The at-most-once ttl for key "%s" must be at least 1 second, got %d.', $key, $operationTtl),
                'Set ttl on #[Idempotent] (or ExecuteOptions) to a dedup window longer than the longest operation '
                . 'plus the redelivery delay, or omit it to use the storage retentionTtl: a Redis marker always expires.',
            );
        }
        $ttl = $operationTtl ?? $this->retentionTtl;

        $reply = $this->client->eval(self::CLAIM, [$redisKey], [$marker, (string) $ttl, $this->cacheResult ? '1' : '0']);
        if (!$this->isClaimReply($reply)) {
            // Neither a claim nor a refusal (e.g. `false` from an adapter on a server error): refusing would
            // ACK a message whose effect never ran, so fail before the effect — still at most once.
            throw new IdempotencyException(\sprintf(
                'Unexpected at-most-once claim reply for key "%s": %s.',
                $key,
                \get_debug_type($reply),
            ));
        }

        if ($reply[0] === 0) {
            return $this->replay($reply[1] ?? null);
        }

        // Marker set. Fire-and-forget: on ANY outcome (success, throwable, crash) it stays until the TTL
        // lapses, so a retry is refused and the effect stays ≤ 1.
        $value = $operation(new AtMostOnceContext($key));
        // A transient outcome cannot be re-run here (the marker stays), but it must not be replayed
        // either: a duplicate gets `null`, as one arriving before the result is stored would.
        if ($value instanceof Uncacheable) {
            return $value->value;
        }

        if ($this->cacheResult && $value !== null) {
            $this->store($key, $redisKey, $marker, $value, $ttl);
        }

        return $value;
    }

    /**
     * Best-effort ONLY: the effect has already happened, so a failed or lost write is logged rather than thrown —
     * an exception here would report a completed effect as failed, and its retry would be refused anyway.
     */
    private function store(string $key, string $redisKey, string $marker, mixed $value, int $ttl): void
    {
        try {
            $stored = $this->client->eval(
                self::STORE,
                [$redisKey],
                [$marker, self::RESULT . (string) $this->serializer->serialize($value)],
            );
            if ($stored === 0) {
                $this->logger?->warning(
                    'Idempotency at-most-once result for key "{key}" was not cached: its marker expired or was '
                    . 'claimed again before the effect finished (outlived the {ttl}s dedup window?), so a '
                    . 'duplicate can run the effect again.',
                    ['key' => $key, 'ttl' => $ttl],
                );
            } elseif ($stored !== 1) {
                // Not a reply the script returns, e.g. `false` from an adapter on a server error.
                $this->logger?->warning(
                    'Idempotency at-most-once result for key "{key}" may not be cached: unexpected reply '
                    . '({replyType}) to the result write; the effect ran.',
                    ['key' => $key, 'replyType' => \get_debug_type($stored), 'reply' => $stored],
                );
            }
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Idempotency at-most-once result for key "{key}" was not cached; the effect ran, '
                . 'a duplicate will get null.',
                ['key' => $key, 'exception' => $e],
            );
        }
    }

    /**
     * `[1]` (fresh claim), `[0]` or `[0, <stored value>]` (duplicate) — the only shapes {@see self::CLAIM} returns.
     *
     * @psalm-assert-if-true array{0: 0|1, 1?: mixed} $reply
     */
    private function isClaimReply(mixed $reply): bool
    {
        if (!\is_array($reply) || !\array_is_list($reply)) {
            return false;
        }

        return match ($reply[0] ?? null) {
            1 => \count($reply) === 1,
            0 => \count($reply) <= 2,
            default => false,
        };
    }

    /**
     * A duplicate: the effect already ran (or was lost). With the result cache off, "already processed"
     * is signalled with `null`; with it on, the stored value is the cached result only once the first
     * caller replaced its marker with it.
     */
    private function replay(mixed $stored): mixed
    {
        if (!$this->cacheResult || !\is_string($stored) || !\str_starts_with($stored, self::RESULT)) {
            return null;
        }

        return $this->serializer->unserialize(\substr($stored, \strlen(self::RESULT)));
    }
}
