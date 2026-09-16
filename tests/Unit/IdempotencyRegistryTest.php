<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit;

use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\IdempotencyRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(IdempotencyRegistry::class)]
#[Covers(Guarantee::class)]
final class IdempotencyRegistryTest
{
    public function resolvesRegisteredAlias(): void
    {
        $driver = $this->driver(Guarantee::ExactlyOnce);
        $registry = new IdempotencyRegistry();

        $registry->register('orders', $driver, Guarantee::ExactlyOnce);

        Assert::same($registry->get('orders'), $driver);
        Assert::true($registry->has('orders'));
    }

    public function atLeastOnceDriverSatisfiesAtLeastOnceAlias(): void
    {
        $registry = new IdempotencyRegistry();

        $registry->register('notifications', $this->driver(Guarantee::AtLeastOnce), Guarantee::AtLeastOnce);

        Assert::true($registry->has('notifications'));
    }

    public function exactlyOnceDriverMaySatisfyAtLeastOnceAlias(): void
    {
        $registry = new IdempotencyRegistry();

        $registry->register('mixed', $this->driver(Guarantee::ExactlyOnce), Guarantee::AtLeastOnce);

        Assert::true($registry->has('mixed'));
    }

    public function atLeastOnceDriverCannotBackExactlyOnceAlias(): never
    {
        $registry = new IdempotencyRegistry();

        Expect::exception(MisconfigurationException::class)->withMessageContaining('ExactlyOnce');

        $registry->register('orders', $this->driver(Guarantee::AtLeastOnce), Guarantee::ExactlyOnce);
    }

    public function atLeastOnceDriverCannotBackAtMostOnceAlias(): never
    {
        // A lease driver (AtLeastOnce) re-runs on retry — the opposite of the "≤ once, refuse the
        // duplicate" contract an AtMostOnce alias declares. Incomparable axes → fail-fast.
        $registry = new IdempotencyRegistry();

        Expect::exception(MisconfigurationException::class)->withMessageContaining('AtMostOnce');

        $registry->register('guard', $this->driver(Guarantee::AtLeastOnce), Guarantee::AtMostOnce);
    }

    public function exactlyOnceDriverMaySatisfyAtMostOnceAlias(): void
    {
        // Only ExactlyOnce carries both axes, so an inbox driver can back an AtMostOnce alias.
        $registry = new IdempotencyRegistry();

        $registry->register('guard', $this->driver(Guarantee::ExactlyOnce), Guarantee::AtMostOnce);

        Assert::true($registry->has('guard'));
    }

    public function unknownAliasThrows(): never
    {
        Expect::exception(MisconfigurationException::class);

        (new IdempotencyRegistry())->get('missing');
    }

    public function nullAliasFallsBackToTheConfiguredDefault(): void
    {
        $driver = $this->driver(Guarantee::ExactlyOnce);
        $registry = new IdempotencyRegistry('orders');
        $registry->register('orders', $driver, Guarantee::ExactlyOnce);

        Assert::same($registry->resolve(null), $driver);
    }

    public function explicitAliasWinsOverTheDefault(): void
    {
        $registry = new IdempotencyRegistry('orders');
        $registry->register('orders', $this->driver(Guarantee::ExactlyOnce), Guarantee::ExactlyOnce);
        $notifications = $this->driver(Guarantee::AtLeastOnce);
        $registry->register('notifications', $notifications, Guarantee::AtLeastOnce);

        Assert::same($registry->resolve('notifications'), $notifications);
    }

    public function nullAliasWithoutADefaultThrows(): never
    {
        $registry = new IdempotencyRegistry();
        $registry->register('orders', $this->driver(Guarantee::ExactlyOnce), Guarantee::ExactlyOnce);

        Expect::exception(MisconfigurationException::class)->withMessageContaining('no default');

        $registry->resolve(null);
    }

    private function driver(Guarantee $guarantee): Idempotency
    {
        return new class ($guarantee) implements Idempotency, GuaranteeProvider {
            public function __construct(private readonly Guarantee $guarantee) {}

            public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
            {
                return $operation;
            }

            public function guarantee(): Guarantee
            {
                return $this->guarantee;
            }
        };
    }
}
