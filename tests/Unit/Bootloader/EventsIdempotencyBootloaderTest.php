<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Bootloader;

use Spiral\Core\Container;
use Spiral\Events\AutowireListenerFactory;
use Spiral\Events\ListenerFactoryInterface;
use Spiral\Idempotency\Bootloader\EventsIdempotencyBootloader;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Events\IdempotentListenerFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EventsIdempotencyBootloader::class)]
final class EventsIdempotencyBootloaderTest
{
    public function overridesTheListenerFactoryBinding(): void
    {
        $container = new Container();
        // The framework binding this bootloader must win over.
        $container->bindSingleton(ListenerFactoryInterface::class, AutowireListenerFactory::class);

        foreach ((new EventsIdempotencyBootloader())->defineSingletons() as $alias => $resolver) {
            $container->bindSingleton($alias, $resolver);
        }

        Assert::instanceOf($container->get(ListenerFactoryInterface::class), IdempotentListenerFactory::class);
    }

    public function dependsOnTheEventsBootloaderForBindingOrder(): void
    {
        // Ordering, not services: only a ListenerFactoryInterface binding registered after
        // EventsBootloader's own SINGLETONS survives.
        $dependencies = (new EventsIdempotencyBootloader())->defineDependencies();

        Assert::contains($dependencies, IdempotencyBootloader::class);
        Assert::contains($dependencies, \Spiral\Events\Bootloader\EventsBootloader::class);
    }
}
