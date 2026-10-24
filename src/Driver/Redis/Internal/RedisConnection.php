<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Driver\Redis\PredisCommands;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Exception\MisconfigurationException;

/**
 * Finds the Redis connection for the Redis factories: a {@see RedisCommands} binding wins; without one a
 * `\Predis\ClientInterface` binding is wrapped in {@see PredisCommands}.
 *
 * @internal Shared by the Redis storage factories; not part of the public API.
 */
final class RedisConnection
{
    /**
     * @param class-string $config the storage config asking, named in the failure message
     */
    public static function resolve(ContainerInterface $container, string $config): RedisCommands
    {
        if ($container->has(RedisCommands::class)) {
            /** @var RedisCommands */
            return $container->get(RedisCommands::class);
        }

        if (\interface_exists(\Predis\ClientInterface::class) && $container->has(\Predis\ClientInterface::class)) {
            /** @var \Predis\ClientInterface $client */
            $client = $container->get(\Predis\ClientInterface::class);

            return new PredisCommands($client);
        }

        throw new MisconfigurationException(
            $config . ' needs a Redis connection, but the container binds neither '
            . RedisCommands::class . ' nor \Predis\ClientInterface.',
            'Bind Spiral\Idempotency\Driver\Redis\RedisCommands to an adapter over the Redis client '
            . 'your application already uses, or install predis/predis and bind \Predis\ClientInterface.',
        );
    }
}
