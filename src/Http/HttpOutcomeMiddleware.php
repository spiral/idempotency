<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\Exception\OutcomeUnknownException;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\FailureClassifier;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Idempotency\Uncacheable;

/**
 * HTTP outcome middleware: maps the idempotent call to/from an HTTP response. Replaces the old
 * scope-bound `HttpResultCodec`, now living in the pipeline.
 *
 *  - wraps the operation so its {@see ResponseInterface} is snapshotted before the handler caches it
 *    (a PSR-7 response is not cleanly serializable);
 *  - rebuilds the response from the snapshot on the way out (via PSR-17 factories) and adds the
 *    `Idempotency-Key` / `Idempotency-Replay` headers;
 *  - turns a {@see LockedException} into `409 Conflict` + `Retry-After`;
 *  - turns an {@see OutcomeUnknownException} (an AtMostOnce duplicate with no stored outcome) into
 *    `409 Conflict` WITHOUT `Retry-After` — nothing tells when, or whether, an outcome gets stored;
 *  - turns a {@see MissingKeyException} into `400 Bad Request` (a missing key is a client error, not a 500);
 *  - wraps a non-cacheable response (default: status >= 500, transient) in {@see Uncacheable}, so the
 *    lease handler releases the key and a retry re-runs instead of replaying the error forever;
 *  - with a {@see DomainFailureRenderer} bound, renders a THROWN domain failure into a response
 *    before it is cached, so a replay reproduces the same status (see below).
 *
 * Order-independent w.r.t. the key middleware: the resolved key travels in the response snapshot (read
 * from {@see IdempotencyContext::getKey()} inside the operation), so the replay header survives even if
 * this middleware runs outside the key middleware. Recommended as the OUTERMOST http middleware, so it
 * also maps key-resolution failures raised by the inner middleware. Non-response results pass through
 * untouched.
 *
 * Domain failures expressed as a THROWN exception need care, because a throw bypasses the response
 * snapshot: the first attempt is rendered by the application exception handler, while a replay rethrows
 * a {@see \Spiral\Idempotency\Exception\CachedDomainFailureException} (or the exact type, when the
 * exception implements {@see \Spiral\Idempotency\ReplayableFailure}) — usually a DIFFERENT
 * status than the first attempt. Three ways out, best first:
 *
 *  1. return a Response for negative domain outcomes instead of throwing — it is snapshotted and
 *     replayed byte-identically;
 *  2. bind a {@see DomainFailureRenderer}: this middleware then renders Domain-classified
 *     throwables into a response INSIDE the operation, which restores case 1 without changing the
 *     action's style (rows cached BEFORE the renderer was bound still replay as a throw);
 *  3. map {@see \Spiral\Idempotency\Exception\CachedDomainFailureException::$originalClass} in the
 *     application exception handler.
 *
 * @api
 */
final readonly class HttpOutcomeMiddleware implements ResolutionMiddleware
{
    private const SNAPSHOT = '__idempotency_http_response__';

    /**
     * Hop-by-hop / body-framing headers that must not survive into a replayed response: they describe
     * the upstream connection, not the payload, and the emitter re-derives `Content-Length` from the
     * rebuilt body.
     */
    private const SKIP_HEADERS = ['content-length', 'transfer-encoding', 'connection'];

    /** @var \Closure(ResponseInterface): bool */
    private \Closure $cacheable;

    private FailureClassifier $classifier;

    /**
     * @param (\Closure(ResponseInterface): bool)|null $cacheable decides whether a response may be
     *        cached; default: only status < 500 (transient 5xx are re-run, not replayed)
     * @param DomainFailureRenderer|null $failures renders a thrown domain failure into the
     *        response to cache and replay; null (default) rethrows it untouched
     * @param FailureClassifier|null $classifier decides which throwables are Domain and may
     *        therefore be rendered; defaults to {@see DefaultFailureClassifier}. Bind the same classifier
     *        the drivers use (the bootloader does) so both agree on what a domain failure is.
     */
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        ?\Closure $cacheable = null,
        private ?DomainFailureRenderer $failures = null,
        ?FailureClassifier $classifier = null,
    ) {
        $this->cacheable = $cacheable ?? static fn(ResponseInterface $response): bool
            => $response->getStatusCode() < 500;
        $this->classifier = $classifier ?? new DefaultFailureClassifier();
    }

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        $executed = false;
        $ownKey = null;
        $encoded = $call->withOperation(function (IdempotencyContext $ctx) use (&$executed, &$ownKey, $call): mixed {
            $executed = true;
            $ownKey = $ctx->getKey();

            try {
                $result = ($call->operation)($ctx);
            } catch (\Throwable $e) {
                // Rethrows unless a bound renderer turned a Domain failure into a response.
                $result = $this->render($e);
            }

            return $this->encode($result, $ctx->getKey());
        });

        try {
            $cached = $next($encoded);
        } catch (LockedException $e) {
            // Not guarded by $executed: a nested lock leaves this lease key free and an inbox one rolled
            // back, so "retry after N" still holds.
            return $this->conflict($e->lock, $ownKey);
        } catch (OutcomeUnknownException $e) {
            // Thrown after the action ran: a nested idempotent call's failure, not a duplicate request.
            return $executed ? throw $e : $this->outcomeUnknown($e->key);
        } catch (MissingKeyException $e) {
            return $this->badRequest($e->getMessage());
        }

        // Read the key from the snapshot (before decode() turns it back into a bare Response), falling
        // back to the call's key — so the replay header does not depend on this middleware's position.
        $key = \is_array($cached) ? ($cached['key'] ?? null) : null;
        $key ??= $call->key;

        $result = $this->decode($cached);

        return $result instanceof ResponseInterface && \is_string($key) && $key !== ''
            ? $this->decorate($result, $key, replayed: !$executed)
            : $result;
    }

    /**
     * Turn a thrown DOMAIN failure into the response the client should see, so the outcome is cached as a
     * response snapshot and every replay reproduces the same status — the throw path otherwise skips the
     * snapshot entirely and replays as a different exception type (see the class docblock).
     *
     * Runs INSIDE the operation, i.e. beneath the execution pipeline's classifier middleware, so the kind
     * is decided here by the injected {@see $classifier}. Only Domain is rendered: an Infrastructure or Bug
     * failure MUST stay a throwable so the driver frees the key (transport/client retries) or reports it —
     * caching a transient error as the outcome would replay it for the whole retention TTL.
     *
     * @return ResponseInterface the rendered domain outcome
     * @throws \Throwable the original failure, when there is no renderer, the kind is not Domain, or the
     *         renderer declined it
     */
    private function render(\Throwable $e): ResponseInterface
    {
        if ($this->failures === null || $this->classifier->classify($e) !== FailureKind::Domain) {
            throw $e;
        }

        return $this->failures->render($e) ?? throw $e;
    }

    /**
     * @param non-empty-string $key
     */
    private function encode(mixed $result, string $key): mixed
    {
        if (!$result instanceof ResponseInterface) {
            return $result;
        }

        $headers = \array_filter(
            $result->getHeaders(),
            static fn(string $name): bool => !\in_array(\strtolower($name), self::SKIP_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );

        $snapshot = [
            self::SNAPSHOT => true,
            'key' => $key,
            'status' => $result->getStatusCode(),
            'reason' => $result->getReasonPhrase(),
            'headers' => $headers,
            'body' => (string) $result->getBody(),
        ];

        return ($this->cacheable)($result) ? $snapshot : new Uncacheable($snapshot);
    }

    private function decode(mixed $cached): mixed
    {
        if (!\is_array($cached) || ($cached[self::SNAPSHOT] ?? null) !== true) {
            return $cached;
        }

        /** @var array{status: int, reason: string, headers: array<string, list<string>>, body: string} $cached */
        $response = $this->responses->createResponse($cached['status'], $cached['reason']);
        foreach ($cached['headers'] as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($this->streams->createStream($cached['body']));
    }

    /**
     * @param non-empty-string $key
     */
    private function decorate(ResponseInterface $result, string $key, bool $replayed): ResponseInterface
    {
        return $result
            ->withHeader('Idempotency-Key', $key)
            ->withHeader('Idempotency-Replay', $replayed ? 'true' : 'false');
    }

    /**
     * Map a missing idempotency key ({@see MissingKeyException} from the inner key middleware or the
     * interceptor terminal) to `400 Bad Request`. The JSON body shape is a default — override it by
     * replacing this middleware in the transport config.
     */
    private function badRequest(string $message): ResponseInterface
    {
        $payload = (string) \json_encode([
            'error' => 'missing_idempotency_key',
            'message' => $message,
        ]);

        return $this->responses->createResponse(400)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($payload));
    }

    /**
     * @param non-empty-string|null $ownKey this request's key when the lock is held on a nested idempotent
     *        call's key — announced instead of the inner one; null when this request's own key is locked
     */
    private function conflict(Locked $lock, ?string $ownKey = null): ResponseInterface
    {
        $payload = (string) \json_encode([
            'error' => 'conflict',
            'message' => $ownKey === null
                ? 'Request with this idempotency key is already being processed.'
                : 'An operation this request depends on is already being processed.',
            'retry_after' => $lock->retryAfter,
        ]);

        return $this->responses->createResponse(409)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Idempotency-Key', $ownKey ?? $lock->key)
            ->withHeader('Retry-After', (string) $lock->retryAfter)
            ->withBody($this->streams->createStream($payload));
    }

    /**
     * Marked as a replay: the answer comes from the first request's record.
     *
     * @param non-empty-string $key
     */
    private function outcomeUnknown(string $key): ResponseInterface
    {
        $payload = (string) \json_encode([
            'error' => 'outcome_unknown',
            'message' => 'The outcome of the original request with this idempotency key is not available.',
        ]);

        $response = $this->responses->createResponse(409)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($payload));

        return $this->decorate($response, $key, replayed: true);
    }
}
