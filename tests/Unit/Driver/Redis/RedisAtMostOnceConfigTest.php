<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Redis;

use Spiral\Idempotency\Driver\Redis\Internal\RedisAtMostOnceFactory;
use Spiral\Idempotency\Driver\Redis\RedisAtMostOnceConfig;
use Spiral\Idempotency\Guarantee;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RedisAtMostOnceConfig::class)]
final class RedisAtMostOnceConfigTest
{
    public function defaults(): void
    {
        $config = new RedisAtMostOnceConfig();

        Assert::same($config->keyPrefix, 'idempotency:amo:');
        Assert::same($config->retentionTtl, 86400);
        Assert::false($config->cacheResult);
        Assert::same($config->guarantee(), Guarantee::AtMostOnce);
    }

    public function guaranteeReflectsTheConstructedValue(): void
    {
        Assert::same(
            (new RedisAtMostOnceConfig(guarantee: Guarantee::ExactlyOnce))->guarantee(),
            Guarantee::ExactlyOnce,
        );
    }

    public function factoryNamesTheRedisAtMostOnceFactory(): void
    {
        Assert::same((new RedisAtMostOnceConfig())->factory(), RedisAtMostOnceFactory::class);
    }
}
