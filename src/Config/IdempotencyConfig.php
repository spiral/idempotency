<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Config;

use Spiral\Core\InjectableConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;

/**
 * Storage-alias configuration. A handler method carries a semantic alias; the driver, connection and
 * declared guarantee live here as a typed {@see StorageConfig} DTO. Example `app/config/idempotency.php`:
 *
 * ```php
 * use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
 * use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
 * use Spiral\Idempotency\Http\HttpKeyMiddleware;
 * use Spiral\Idempotency\Http\HttpOutcomeMiddleware;
 *
 * return [
 *     'default' => 'orders',
 *     'storages' => [
 *         'orders'        => new CycleInboxConfig(connection: 'default', table: 'inbox'),
 *         'notifications' => new CycleLeaseConfig(connection: 'default', lockTtl: 30, retentionTtl: 86400),
 *     ],
 *     // Per-transport resolution stack, outer → inner. The outcome middleware is outermost so it maps
 *     // both the response and key-resolution failures raised by the inner key middleware. Optional:
 *     // omit the whole section when no transport bootloader is registered.
 *     'transports' => [
 *         'http' => [HttpOutcomeMiddleware::class, HttpKeyMiddleware::class],
 *     ],
 * ];
 * ```
 *
 * @api
 */
final class IdempotencyConfig extends InjectableConfig
{
    public const CONFIG = 'idempotency';

    protected array $config = [
        'default' => null,
        'storages' => [],
        'transports' => [],
    ];

    /**
     * @return array<non-empty-string, StorageConfig>
     */
    public function getStorages(): array
    {
        /** @var array<non-empty-string, StorageConfig> */
        return $this->config['storages'] ?? [];
    }

    /**
     * Ordered resolution-middleware stack for a transport (outer → inner), by class name. The
     * interceptor resolves each through the container and runs them around the storage handler.
     *
     * The `transports` section itself is optional and an absent one reads as empty — an application
     * that only calls {@see \Spiral\Idempotency\IdempotencyRegistry::execute()} registers no transport
     * bootloader and has nothing to put there. What fails fast is a missing stack for a transport that
     * IS dispatched: only a transport bootloader ever asks for one, and answering it with an empty
     * pipeline would deduplicate on nothing, silently disabling idempotency. An explicitly empty list
     * is valid — the minimal setup where the key comes only from the attribute.
     *
     * @param non-empty-string $transport
     * @return list<class-string<\Spiral\Idempotency\Pipeline\ResolutionMiddleware>>
     * @throws MisconfigurationException when this transport has no configured middleware list
     */
    public function getTransport(string $transport): array
    {
        $transports = $this->getTransports();

        \array_key_exists($transport, $transports) or throw new MisconfigurationException(
            \sprintf('Transport "%s" is not configured under "transports.%s" in the idempotency config.', $transport, $transport),
            \sprintf(
                "Add a middleware list for the transport in `config/idempotency.php`:\n\n"
                . "```php\n'transports' => [\n    '%s' => [/* ResolutionMiddleware class names, outer → inner */],\n],\n```\n\n"
                . "An explicitly empty list is valid: the key must then come from the #[Idempotent] attribute.\n"
                . 'If the application does not dispatch over this transport, drop its bootloader instead.',
                $transport,
            ),
        );

        return $transports[$transport];
    }

    /**
     * Every configured transport stack, by transport name. Empty when the section is absent — the
     * class default covers a config array assembled by hand as well as one loaded from a file.
     *
     * @return array<non-empty-string, list<class-string<\Spiral\Idempotency\Pipeline\ResolutionMiddleware>>>
     */
    private function getTransports(): array
    {
        /** @var array<non-empty-string, list<class-string<\Spiral\Idempotency\Pipeline\ResolutionMiddleware>>> */
        return $this->config['transports'] ?? [];
    }

    /**
     * @param non-empty-string $alias
     */
    public function getStorage(string $alias): StorageConfig
    {
        return $this->getStorages()[$alias] ?? throw new MisconfigurationException(
            \sprintf('No idempotency storage configured under alias "%s".', $alias),
            \sprintf(
                'Register the alias under `storages` in `config/idempotency.php`, e.g. '
                . "`'%s' => new CycleLeaseConfig(...)` or `new CycleInboxConfig(...)`.",
                $alias,
            ),
        );
    }

    /**
     * @return non-empty-string|null
     */
    public function getDefault(): ?string
    {
        /** @var non-empty-string|null */
        return $this->config['default'] ?? null;
    }
}
