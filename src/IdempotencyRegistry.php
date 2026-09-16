<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

use Spiral\Idempotency\Exception\MisconfigurationException;

/**
 * Resolves a semantic storage alias (from the {@see Attribute\Idempotent} attribute / config) to a
 * concrete {@see Idempotency} driver.
 *
 * At registration it verifies — fail-fast — that the driver can actually back the *declared*
 * guarantee. This is the checkable part: not "will the handler reach the guarantee" (not
 * statically provable), but "does the alias lie about the driver's capability".
 *
 * @api
 */
final class IdempotencyRegistry
{
    /** @var array<non-empty-string, Idempotency> */
    private array $drivers = [];

    /**
     * @param non-empty-string|null $default alias serving an {@see Attribute\Idempotent} that names no
     *        storage; `null` makes the attribute's `storage:` argument mandatory
     */
    public function __construct(
        private readonly ?string $default = null,
    ) {}

    public function register(string $alias, Idempotency $driver, Guarantee $declared): void
    {
        if ($driver instanceof GuaranteeProvider && !$driver->guarantee()->satisfies($declared)) {
            throw new MisconfigurationException(
                \sprintf(
                    'Storage alias "%s" declares guarantee %s, but driver %s can only provide %s.',
                    $alias,
                    $declared->name,
                    $driver::class,
                    $driver->guarantee()->name,
                ),
                'Lower the declared guarantee of the alias to the driver\'s capability '
                . '(e.g. `Guarantee::AtLeastOnce` for a lease driver), or back the alias with a driver '
                . 'that can provide it (the inbox driver for ExactlyOnce).',
            );
        }

        /** @var non-empty-string $alias */
        $this->drivers[$alias] = $driver;
    }

    public function get(string $alias): Idempotency
    {
        return $this->drivers[$alias] ?? throw new MisconfigurationException(
            \sprintf('No idempotency storage registered under alias "%s".', $alias),
            \sprintf(
                'Register the "%s" alias under `storages` in `config/idempotency.php`, or fix the '
                . '`storage:` argument of the #[Idempotent] attribute.',
                $alias,
            ),
        );
    }

    /**
     * Resolve an optional alias: a handler that names no storage falls back to the configured `default`.
     *
     * @throws MisconfigurationException when neither the caller nor the config names an alias
     */
    public function resolve(?string $alias): Idempotency
    {
        return $this->get($alias ?? $this->default ?? throw new MisconfigurationException(
            'No idempotency storage alias given, and the idempotency config declares no default.',
            'Pass `storage:` to the #[Idempotent] attribute, or name a fallback alias under `default` '
            . "in `config/idempotency.php`, e.g. `'default' => 'orders'`.",
        ));
    }

    public function has(string $alias): bool
    {
        return isset($this->drivers[$alias]);
    }
}
