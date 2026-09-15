<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Events\AutowireListenerFactory;
use Spiral\Events\Bootloader\EventsBootloader;
use Spiral\Events\ListenerFactoryInterface;
use Spiral\Idempotency\Events\IdempotentListenerFactory;

/**
 * Opt-in Spiral Events wiring: replaces the framework's {@see ListenerFactoryInterface} binding with
 * {@see IdempotentListenerFactory}, which wraps every `#[Idempotent]` listener method in its storage.
 *
 * Unlike the other transports this binds no interceptor and needs no `transports.*` config section:
 * events are dispatched through PSR-14, not through a domain core, so there is no call context for a
 * resolution middleware to read. The key comes from the attribute's `key` path or from
 * {@see \Spiral\Idempotency\Events\HasIdempotencyKey} on the event itself.
 *
 * {@see EventsBootloader} is a declared dependency for ORDER, not for its services: it binds the
 * default `AutowireListenerFactory` in its own `SINGLETONS`, and only a binding registered after it
 * wins. The factory it hands to the `AttributeProcessor` is resolved later, in `boot()`, so the
 * override is in place by the time any listener closure is built.
 *
 * @api
 */
final class EventsIdempotencyBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [IdempotencyBootloader::class, EventsBootloader::class];
    }

    public function defineSingletons(): array
    {
        return [
            ListenerFactoryInterface::class => static fn(
                AutowireListenerFactory $inner,
                ContainerInterface $container,
            ): IdempotentListenerFactory => new IdempotentListenerFactory($inner, $container),
        ];
    }
}
