# Changelog

## [0.4.1](https://github.com/spiral/idempotency/compare/0.4.0...0.4.1) (2026-09-16)


### Features

* **config:** honour the default storage alias in #[Idempotent] ([#24](https://github.com/spiral/idempotency/issues/24)) ([f9e96b8](https://github.com/spiral/idempotency/commit/f9e96b87440ab95101abee66ae7b21978f56472b))

## [0.4.0](https://github.com/spiral/idempotency/compare/0.3.0...0.4.0) (2026-09-15)


### ⚠ BREAKING CHANGES

* **failure:** the queue contract is read before the `\Error` rule, so an `\Error` that implements `RetryableExceptionInterface` with `isRetryable() === false` is now Domain (cached and replayed) where it used to be Infrastructure (key released). No other input changes kind; an app relying on the old result must bind its own FailureClassifier.
* **failure:** queue now defaults to Release, so a failed job frees its key and the broker's redelivery re-runs the handler instead of replaying a cached failure — previously that took wrapping every failure into a retryable exception. HTTP, gRPC and a direct execute() stay on Cache.
* **key:** reject a boolean leaf in the argument key path
* **key:** extract the attribute arg-path into ArgumentKeyResolver
* **attribute:** for a target whose method is inherited, the default key scope changes from `Declaring::method` to `Concrete::method` on every transport, an HTTP controller inheriting an action included. Keys written under the old scope no longer match, so a replay begun before the upgrade re-runs the operation instead of replaying it; drain those in-flight keys before deploying, or pin the old key space with an explicit `scope:` on the attribute.

### Features

* **attribute:** allow #[Idempotent] on classes ([4f247c2](https://github.com/spiral/idempotency/commit/4f247c2e82554e1d20ccb8a7b564ae4ce9e19e60))
* **events:** idempotent PSR-14 listeners via IdempotentListenerFactory ([a497870](https://github.com/spiral/idempotency/commit/a497870c51b9a61d6d93ed0d99064a9ee1b8db48))
* **failure:** honour the queue retry contract in the default classifier ([02ad947](https://github.com/spiral/idempotency/commit/02ad947ef673878d25119cf64439f25b87a2e5cf))
* **failure:** per-operation FailurePolicy (Cache / Release) ([1fc048a](https://github.com/spiral/idempotency/commit/1fc048a61411297f560d3cbe760f4566c320a01d))
* **key:** extract the attribute arg-path into ArgumentKeyResolver ([d27c8a7](https://github.com/spiral/idempotency/commit/d27c8a7c2ed19ff6641eb08c739ee40ba198bc8a))
* **memory:** public MemoryLeaseConfig for a process-local lease ([bba229e](https://github.com/spiral/idempotency/commit/bba229e4a5a5034efc3b0dd72979fa67ad732843))
* **queue:** optional job-id fallback for the key ([f70928a](https://github.com/spiral/idempotency/commit/f70928acd60107a265ea80a1d21c01848965bc25))


### Bug Fixes

* **key:** reject a boolean leaf in the argument key path ([3b0c98e](https://github.com/spiral/idempotency/commit/3b0c98ebf60091048fc78a7b67d411aaf22843b8))


### Documentation

* **config:** the transports section is optional, its named stacks are not ([2e74057](https://github.com/spiral/idempotency/commit/2e740577e98cc14b65c2cff90e3896216839a1b9))


### Code Refactoring

* **attribute:** extract the Idempotent lookup into IdempotentLocator ([a497870](https://github.com/spiral/idempotency/commit/a497870c51b9a61d6d93ed0d99064a9ee1b8db48))

## [0.3.0](https://github.com/spiral/idempotency/compare/0.2.1...0.3.0) (2026-09-03)


### ⚠ BREAKING CHANGES

* every *Interface type is renamed (IdempotencyInterface -> Idempotency, IdempotencyInterceptorInterface -> IdempotencyInterceptor, KeyResolverInterface -> KeyResolver, LeaseManagerInterface -> LeaseManager, LeaseStorageInterface -> LeaseStorage, TokenFactoryInterface -> TokenFactory, FailureClassifierInterface -> FailureClassifier, StorageFactoryInterface -> StorageFactory, GuaranteeProviderInterface -> GuaranteeProvider, RetryableInterface -> Retryable, ReplayableFailureInterface -> ReplayableFailure, DomainFailureMapperInterface -> DomainFailureMapper, DomainFailureRendererInterface -> DomainFailureRenderer, SchemaNamingInterface -> SchemaNaming, RedisCommandsInterface -> RedisCommands); the concrete interceptor class is now PipelineIdempotencyInterceptor.

### Features

* **redis:** decouple the lease storage from predis ([#3](https://github.com/spiral/idempotency/issues/3)) ([af3693b](https://github.com/spiral/idempotency/commit/af3693b8b652f286f1889022b8d682382da28283))


### Code Refactoring

* drop the Interface suffix from every interface ([#5](https://github.com/spiral/idempotency/issues/5)) ([31a1f42](https://github.com/spiral/idempotency/commit/31a1f4220e1977ca844a13c4c7e092ea44fd5d14))

## [0.2.1](https://github.com/spiral/idempotency/compare/0.2.0...0.2.1) (2026-08-28)


### Documentation

* **skills:** state the mandatory transports.&lt;name&gt; contract; keep code samples copy-clean ([2803d79](https://github.com/spiral/idempotency/commit/2803d794557ffa60c67f03e0e0151f002fe54fd2))

## [0.2.0](https://github.com/spiral/idempotency/compare/0.1.0...0.2.0) (2026-08-05)


### Features

* AtMostOnce dedup-guard guarantee (fire-once, optional best-effort result) ([bf2de86](https://github.com/spiral/idempotency/commit/bf2de867e8055c2bb4a458fdba8a0585926426ce))
* bind IdempotencyInterceptor to the http transport in HttpIdempotencyBootloader ([890d505](https://github.com/spiral/idempotency/commit/890d505adfc463a6f10cd15f83bf81929d656320))
* classifier execution middleware and ClassifiedException marker ([721f37f](https://github.com/spiral/idempotency/commit/721f37f0920e3b5d7c3132ba5d5077037e959076))
* config-driven idempotency pipeline over HTTP ([cd040a8](https://github.com/spiral/idempotency/commit/cd040a8013c385f3dbd61e2645bbf34f49166e3c))
* Cycle driver for lease and inbox storage ([c83fa44](https://github.com/spiral/idempotency/commit/c83fa44120c0e6a9ea881c2080b0f5d08f059c07))
* expose the interceptor via a root scoped proxy over IdempotencyInterceptorInterface ([60dda1c](https://github.com/spiral/idempotency/commit/60dda1cc8c3d17f9ce0b6ab2b9ea41f5f019826f))
* fail fast on unresolvable key path, unknown transport and class-level attribute ([9aa9651](https://github.com/spiral/idempotency/commit/9aa96512facf438fed0e644e5f20216319fbc11c))
* faithful domain-failure replay via ReplayableFailureInterface ([f16e3c4](https://github.com/spiral/idempotency/commit/f16e3c411f1a7a35c48ee9d6978860a97d490a25))
* framework integration layer for declarative idempotency ([d4cc057](https://github.com/spiral/idempotency/commit/d4cc05707bd4c6329a99bed0c0dab5ca06096cfd))
* **gc:** garbage-collect expired idempotency rows (CycleGarbageCollector) ([91a40d5](https://github.com/spiral/idempotency/commit/91a40d57bf5cbd15ca74af439f21446243d9940b))
* **grpc:** carry google.rpc.RetryInfo on the ABORTED lock conflict ([61504a6](https://github.com/spiral/idempotency/commit/61504a62e1b56997c39d214a124b09afb2046454))
* **grpc:** idempotent gRPC server transport (Locked -&gt; ABORTED) ([56f6412](https://github.com/spiral/idempotency/commit/56f641236a97296a1982b35b9baf1cb7f912aac8))
* **grpc:** snapshot thrown gRPC statuses so a replay answers identically ([470dc0f](https://github.com/spiral/idempotency/commit/470dc0fcce8f91236dcc6468a5c0a3967f46a2c3))
* **http:** render thrown domain failures into cached responses ([acd4826](https://github.com/spiral/idempotency/commit/acd4826c8a1f564b1e21b65e8a48cc71e4c524a3))
* internal idempotency implementations ([1d16aae](https://github.com/spiral/idempotency/commit/1d16aaec7e193ef12a4fa47400c932a15b53b3ef))
* **lease:** cooperative lease renewal via renew() heartbeat and FiberRenewalMiddleware ([b53c5e6](https://github.com/spiral/idempotency/commit/b53c5e6006ac77391a72b1f50506274d444c90cd))
* make MisconfigurationException friendly with actionable solutions ([ae42d92](https://github.com/spiral/idempotency/commit/ae42d92733ae4ee63a12d7d8cb38336b7fcda843))
* map a missing idempotency key to 400 instead of 500 ([418d310](https://github.com/spiral/idempotency/commit/418d3105670ec3b6a2836c9c2249b48ba41eecb4))
* namespace idempotency keys by operation identity with attribute scope override ([e163833](https://github.com/spiral/idempotency/commit/e1638337453067436355ebe0f8346fcfb3e41e72))
* per-call TTL overrides via ExecuteOptions ([869777a](https://github.com/spiral/idempotency/commit/869777a47535bb2974a2eaac040e183608668b30))
* pipeline scaffolding with universal orchestrator ([338e572](https://github.com/spiral/idempotency/commit/338e5727026c840990a0c02c37a297ca66535da4))
* public idempotency API and core contracts ([0cc2556](https://github.com/spiral/idempotency/commit/0cc25565ec679f4bfc842d3ade03d0573b3880ba))
* **queue:** idempotent queue/jobs transport over spiral/queue ([72361ec](https://github.com/spiral/idempotency/commit/72361ecdd15e906ae7b7334dda1e784ae95e696d))
* **redis:** Redis/Valkey lease backend (AtLeastOnce) ([838e9f7](https://github.com/spiral/idempotency/commit/838e9f7d4463aa9d3773e741114e9ebe4693afed))
* skip caching transient responses (Uncacheable) ([6ffe18f](https://github.com/spiral/idempotency/commit/6ffe18f64862cadbb0eaa6980e46f2c474786ce0))


### Bug Fixes

* bind idempotency context in an isolated scope ([36abcd6](https://github.com/spiral/idempotency/commit/36abcd6afad86c4a4bc3a826b22c95e10333bc7b))
* make http replay headers independent of middleware order ([0551324](https://github.com/spiral/idempotency/commit/0551324886ad4fbf8675d01113901651a6c1fc33))
* MySQL false lease-loss on no-op renew ([ec2a9c7](https://github.com/spiral/idempotency/commit/ec2a9c7602cb701753e1ab75fc9c54f98a8f5ba4))
* preserve the operation outcome when the lease is lost at the terminal transition ([939a2ee](https://github.com/spiral/idempotency/commit/939a2ee58e45f6e9e3f117a33784ff22519ffcf5))


### Documentation

* clarify interceptor registration (alias proxy, no HandlerInterface boilerplate) ([460a2fa](https://github.com/spiral/idempotency/commit/460a2fa80ab0688d978ef1956b65f8dae9c32b63))
* README with quick start, guarantees and customization guide ([14d1767](https://github.com/spiral/idempotency/commit/14d17676b0ef4d393960f5f1569611608ddadd40))
* **skills:** ship an AI skill for consumer projects ([51da15d](https://github.com/spiral/idempotency/commit/51da15d3a1e2a41192d97e4d2bb61c23f0de62b0))


### Code Refactoring

* bind the http interceptor in the http dispatcher scope instead of root ([4ff41a8](https://github.com/spiral/idempotency/commit/4ff41a872606fa3411a29dfa18b86431575e096f))
* detach ClassifiedException from the public exception hierarchy ([6e2ec0c](https://github.com/spiral/idempotency/commit/6e2ec0cc303edd62fca6e2157ed787f517110091))
* lease handler runs the execution pipeline ([42b213c](https://github.com/spiral/idempotency/commit/42b213c0e26a102728dedd5d4a0f67deee3ec9d7))
* minor hardening (pipeline cache, container serializer, schema definitions, docs) ([58cf8bd](https://github.com/spiral/idempotency/commit/58cf8bd9aaf91b85d45d551e4155acbfa595ee52))
