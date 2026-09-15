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
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\PipelineIdempotencyInterceptor;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\KeyResolver;

/**
 * Opt-in Queue wiring for the idempotency pipeline. No consumer-scope bindings: the queue resolution
 * middleware ({@see \Spiral\Idempotency\Queue\QueueKeyMiddleware},
 * {@see \Spiral\Idempotency\Queue\QueueRetryMiddleware}) are plain autowired services listed in the
 * `transports.queue` config stack; the job context travels inside the call context, so nothing is
 * per-job-scoped here.
 *
 * The interceptor is exposed under the {@see IdempotencyInterceptor} alias in two layers
 * (the framework's scoped-proxy pattern, cf. `TracerInterface` / `AuthContextInterface`):
 *
 *  - the REAL {@see PipelineIdempotencyInterceptor} (flavored with `transport: 'queue'`) is a singleton of the
 *    `queue` DISPATCHER scope — the scope lives as long as the queue dispatcher itself, so the
 *    interceptor and its internal caches survive across consumed jobs;
 *  - the ROOT container holds only a {@see Proxy}: a domain core may be built in any scope — every
 *    `intercept()` call resolves the real interceptor from the ACTIVE dispatcher scope at call time.
 *    Outside such a scope the proxy fails fast with a {@see MisconfigurationException} instead of
 *    silently picking a wrong transport. The HTTP integration binds its `transport: 'http'` flavor in
 *    the `http` scope under the same alias.
 *
 * An app registers the consume interceptor via `queue.php` `interceptors.consume` (or
 * `QueueBootloader::addConsumeInterceptor(IdempotencyInterceptor::class)`) and lists the queue
 * middleware under `transports.queue` in `config/idempotency.php`. Spiral's default-on
 * `RetryPolicyInterceptor` should sit OUTER of ours so it catches the re-thrown
 * {@see \Spiral\Idempotency\Queue\RetryableLockException} and re-enqueues the job.
 *
 * Registering this bootloader makes `transports.queue` mandatory — at least as an empty list. A missing
 * stack is treated as a misconfiguration: {@see IdempotencyConfig::getTransport()} throws
 * {@see MisconfigurationException} on the first `#[Idempotent]` call rather than running an empty
 * pipeline that silently disables idempotency. An explicit `'queue' => []` is valid (key comes only from
 * the attribute, no queue middleware); the `transports` section as a whole stays optional for an app
 * that registers no transport bootloader at all.
 *
 * @api
 */
final class QueueIdempotencyBootloader extends Bootloader
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
            // NOTE: HttpIdempotencyBootloader binds this SAME alias to an identical Proxy in root — the
            // double binding is intentional and harmless (Spiral merges bindings; last wins; both bind
            // the same Proxy), so an app running either transport works without the other bootloader.
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
        // The `queue` dispatcher scope, not root: dispatcher-lifetime singleton, and the alias stays
        // free for other transports' scopes.
        $binder->getBinder('queue')->bindSingleton(
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
                transport: 'queue',
                // The broker owns redelivery: a failing job must leave its key free so the retry runs
                // the handler again instead of replaying a cached failure.
                failurePolicy: FailurePolicy::Release,
            ),
        );
    }
}
