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
 * Opt-in gRPC wiring for the idempotency pipeline. No per-call-scope bindings: the gRPC resolution
 * middleware ({@see \Spiral\Idempotency\Grpc\GrpcKeyMiddleware},
 * {@see \Spiral\Idempotency\Grpc\GrpcOutcomeMiddleware}) are plain autowired services listed in the
 * `transports.grpc` config stack; the gRPC context travels inside the call context, so nothing is
 * per-call-scoped here.
 *
 * The interceptor is exposed under the {@see IdempotencyInterceptor} alias in two layers
 * (the framework's scoped-proxy pattern, cf. `TracerInterface` / `AuthContextInterface`):
 *
 *  - the REAL {@see PipelineIdempotencyInterceptor} (flavored with `transport: 'grpc'`) is a singleton of the
 *    `grpc` DISPATCHER scope — the scope lives as long as the gRPC dispatcher itself, so the interceptor
 *    and its internal caches survive across gRPC calls;
 *  - the ROOT container holds only a {@see Proxy}: a domain core may be built in any scope — every
 *    `intercept()` call resolves the real interceptor from the ACTIVE dispatcher scope at call time.
 *    Outside such a scope the proxy fails fast with a {@see MisconfigurationException} instead of
 *    silently picking a wrong transport. The HTTP/Queue integrations bind their `transport: 'http'` /
 *    `transport: 'queue'` flavors in their own scopes under the same alias.
 *
 * An app registers the gRPC server interceptor via `config/grpc.php` `interceptors` (or
 * `GRPCBootloader::addInterceptor(IdempotencyInterceptor::class)`) and lists the gRPC middleware
 * under `transports.grpc` in `config/idempotency.php`.
 *
 * A thrown {@see \Spiral\RoadRunner\GRPC\Exception\GRPCException} is snapshotted and replayed as the same
 * status out of the box — nothing to bind. Optional: bind
 * {@see \Spiral\Idempotency\Grpc\DomainFailureMapper} when the service expresses negative outcomes
 * as plain domain exceptions instead of gRPC statuses; see {@see \Spiral\Idempotency\Grpc\GrpcOutcomeMiddleware}.
 *
 * Registering this bootloader makes `transports.grpc` mandatory — at least as an empty list. A missing
 * stack is treated as a misconfiguration: {@see IdempotencyConfig::getTransport()} throws
 * {@see MisconfigurationException} on the first `#[Idempotent]` call rather than running an empty
 * pipeline that silently disables idempotency. An explicit `'grpc' => []` is valid (key comes only from
 * the attribute, no gRPC middleware); the `transports` section as a whole stays optional for an app that
 * registers no transport bootloader at all.
 *
 * @api
 */
final class GrpcIdempotencyBootloader extends Bootloader
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
            // NOTE: HttpIdempotencyBootloader / QueueIdempotencyBootloader bind this SAME alias to an
            // identical Proxy in root — the double binding is intentional and harmless (Spiral merges
            // bindings; last wins; all bind the same Proxy), so an app running any subset of the
            // transports works without the others' bootloaders.
            IdempotencyInterceptor::class => new Proxy(
                IdempotencyInterceptor::class,
                false,
                static fn(): never => throw new MisconfigurationException(
                    'IdempotencyInterceptor is used outside of a transport dispatcher scope.',
                    'The real interceptor is bound per transport: HttpIdempotencyBootloader binds the '
                    . 'http flavor inside the `http` scope, QueueIdempotencyBootloader binds the queue '
                    . 'flavor inside the `queue` scope, GrpcIdempotencyBootloader binds the grpc flavor '
                    . 'inside the `grpc` scope. Invoke the interceptor while a transport scope is active, '
                    . 'or register the integration bootloader for this transport.',
                ),
            ),
        ];
    }

    public function init(BinderInterface $binder): void
    {
        // The `grpc` dispatcher scope, not root: dispatcher-lifetime singleton, and the alias stays
        // free for other transports' scopes.
        $binder->getBinder('grpc')->bindSingleton(
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
                transport: 'grpc',
                // Like HTTP: the caller repeats the RPC itself, so a failure is a cached outcome of this
                // key and the retry answers with the same status.
                failurePolicy: FailurePolicy::Cache,
            ),
        );
    }
}
