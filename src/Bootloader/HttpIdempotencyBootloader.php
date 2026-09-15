<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\BinderInterface;
use Spiral\Core\Config\Proxy;
use Spiral\Idempotency\ArgumentKeyResolver;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\KeyResolver;

/**
 * Opt-in HTTP wiring for the idempotency pipeline. No request-scope bindings: the HTTP resolution
 * middleware ({@see \Spiral\Idempotency\Http\HttpKeyMiddleware},
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}) are plain autowired services listed in the
 * `transports.http` config stack; the request travels inside the call context, so nothing is
 * per-request-scoped here.
 *
 * The interceptor is exposed under the {@see IdempotencyInterceptor} alias in two layers
 * (the framework's scoped-proxy pattern, cf. `TracerInterface` / `AuthContextInterface`):
 *
 *  - the REAL {@see PipelineIdempotencyInterceptor} (flavored with `transport: 'http'`) is a singleton of the
 *    `http` DISPATCHER scope — the scope lives as long as the HTTP dispatcher itself (per-request
 *    state resets in the nested `http-request` scope), so the interceptor and its internal caches
 *    survive across requests;
 *  - the ROOT container holds only a {@see Proxy}: a domain core may be built in any scope
 *    (e.g. a classic `DomainBootloader` root singleton) — every `intercept()` call resolves the real
 *    interceptor from the ACTIVE dispatcher scope at call time. Outside such a scope the proxy fails
 *    fast with a {@see MisconfigurationException} instead of silently picking a wrong transport.
 *    A future queue integration binds its `transport: 'queue'` flavor in the `queue` scope under the
 *    same alias.
 *
 * An app adds `IdempotencyInterceptor::class` to its HTTP domain core's interceptor list and
 * lists the HTTP middleware under `transports.http` in `config/idempotency.php`.
 *
 * Optional: bind {@see \Spiral\Idempotency\Http\DomainFailureRenderer} to render THROWN domain
 * failures into responses before they are cached — that is what keeps the HTTP status of a replay equal
 * to the first attempt. Unbound (default), such failures are rethrown and the replay may render
 * differently; see {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}.
 *
 * The app MUST declare `transports.http` — at least as an empty list. A missing section is treated as a
 * misconfiguration: {@see IdempotencyConfig::getTransport()} throws
 * {@see MisconfigurationException} on the first `#[Idempotent]` call rather
 * than running an empty pipeline that silently disables idempotency. An explicit `'http' => []` is valid
 * (key comes only from the attribute, no HTTP middleware).
 *
 * @api
 */
final class HttpIdempotencyBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [IdempotencyBootloader::class];
    }

    public function defineBindings(): array
    {
        return [
            // Root: a scoped proxy only. Each intercept() forwards to the flavor bound in the active
            // dispatcher scope; the fallback fires when no scope on the chain bound the alias.
            //
            // NOTE: QueueIdempotencyBootloader binds this SAME alias to an identical Proxy in root — the
            // double binding is intentional and harmless (Spiral merges bindings; last wins; both bind
            // the same Proxy), so a queue-only app works without this HTTP bootloader.
            IdempotencyInterceptor::class => new Proxy(
                IdempotencyInterceptor::class,
                false,
                static fn(): never => throw new MisconfigurationException(
                    'IdempotencyInterceptor is used outside of a transport dispatcher scope.',
                    'The real interceptor is bound per transport: HttpIdempotencyBootloader binds the '
                    . 'http flavor inside the `http` scope, QueueIdempotencyBootloader binds the queue '
                    . 'flavor inside the `queue` scope. Invoke the interceptor while a transport scope is '
                    . 'active, or register the integration bootloader for this transport.',
                ),
            ),
        ];
    }

    public function init(BinderInterface $binder): void
    {
        // The `http` dispatcher scope, not root and not `http-request`: dispatcher-lifetime singleton,
        // and the alias stays free for other transports' scopes.
        $binder->getBinder('http')->bindSingleton(
            IdempotencyInterceptor::class,
            static fn(
                IdempotencyRegistry $registry,
                KeyResolver $keys,
                ArgumentKeyResolver $arguments,
                ContainerInterface $container,
                IdempotencyConfig $config,
            ): PipelineIdempotencyInterceptor => new PipelineIdempotencyInterceptor(
                $registry,
                $keys,
                $arguments,
                $container,
                $config,
                transport: 'http',
            ),
        );
    }
}
