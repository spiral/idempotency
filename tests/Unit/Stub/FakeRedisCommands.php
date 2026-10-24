<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Stub;

use Spiral\Idempotency\Driver\Redis\RedisCommands;

/**
 * In-memory stand-in for the two at-most-once scripts: the claim (`SET ... NX EX`) and the result CAS.
 * It tells them apart by the `'NX'` token in the script text and replies in the same shapes a Redis
 * client decodes. Every call is recorded so a test can assert the TTL and the key it was sent.
 */
final class FakeRedisCommands implements RedisCommands
{
    /** @var array<string, array{value: string, ttlMs: int}> */
    public array $store = [];

    /** @var list<array{script: string, keys: list<string>, args: list<string>}> */
    public array $calls = [];

    public function __construct(
        private readonly ?\Throwable $storeFailure = null,
    ) {}

    public function eval(string $script, array $keys, array $args): mixed
    {
        $this->calls[] = ['script' => $script, 'keys' => $keys, 'args' => $args];
        $key = $keys[0];

        if (\str_contains($script, "'NX'")) {
            if (!isset($this->store[$key])) {
                $this->store[$key] = ['value' => $args[0], 'ttlMs' => (int) $args[1] * 1000];
                return [1];
            }

            return $args[2] === '1' ? [0, $this->store[$key]['value']] : [0];
        }

        if ($this->storeFailure !== null) {
            throw $this->storeFailure;
        }

        if (($this->store[$key]['value'] ?? null) !== $args[0]) {
            return 0;
        }

        $this->store[$key]['value'] = $args[1];

        return 1;
    }

    public function hgetall(string $key): array
    {
        throw new \LogicException('The at-most-once driver must not issue HGETALL.');
    }

    public function pttl(string $key): int
    {
        throw new \LogicException('The at-most-once driver must not issue PTTL outside its scripts.');
    }

    /**
     * Simulates the server expiring the key.
     */
    public function expire(string $key): void
    {
        unset($this->store[$key]);
    }
}
