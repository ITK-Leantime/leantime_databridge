<?php

namespace Leantime\Plugins\Databridge\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Leantime\Core\Http\IncomingRequest;
use Leantime\Plugins\Databridge\Exceptions\InvalidApiKeyException;
use Leantime\Plugins\Databridge\Exceptions\OperationNotGrantedException;
use Leantime\Plugins\Databridge\Model\Operation;
use Leantime\Plugins\Databridge\Services\ApiUsers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware enforcing the plugin's own API-key authentication.
 *
 * Usage: ->middleware(ApiKeyAuth::class.':read'). Core auth is bypassed for the whole
 * /api/databridge/* prefix (see register.php), so every route in this plugin MUST attach
 * this middleware with the operation it requires.
 */
class ApiKeyAuth
{
    public const HEADER = 'x-api-key';

    public const REQUEST_ATTRIBUTE = 'databridge.apiUser';

    /**
     * Core's failed-auth limiter sits behind the publicActions early return, so whitelisted
     * routes never reach it — this middleware must throttle key guessing itself. Defaults
     * mirror core's budget (20 failed attempts per IP per minute) and are overridable via
     * the LEAN_DATABRIDGE_RATELIMIT_* env vars (see maxFailedAttempts()/decaySeconds()).
     */
    private const DEFAULT_MAX_FAILED_ATTEMPTS = 20;

    private const DEFAULT_FAILED_ATTEMPT_DECAY_SECONDS = 60;

    public function __construct(
        private readonly ApiUsers $apiUsers,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Authenticate the API key header and authorize the required operation.
     *
     * @param  string  $operation  Backed value of a Model\Operation case, declared on the route.
     */
    public function handle(IncomingRequest $request, Closure $next, string $operation): Response
    {
        // A typo in routes.php is a developer error: from() throws, failing loudly with a 500.
        $requiredOperation = Operation::from($operation);

        $throttleKey = 'databridge-auth-failures:'.$request->getClientIp();

        if ($this->limiter->tooManyAttempts($throttleKey, $this->maxFailedAttempts())) {
            Log::warning('Databridge auth: too many failed authentication attempts', ['ip' => $request->getClientIp()]);

            return new JsonResponse(['error' => 'Too many failed authentication attempts. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $user = $this->apiUsers->authenticate($request->headers->get(self::HEADER));
            $this->apiUsers->authorize($user, $requiredOperation);
        } catch (InvalidApiKeyException) {
            $this->limiter->hit($throttleKey, $this->decaySeconds());
            Log::warning('Databridge auth: rejected request with missing or unknown API key', ['ip' => $request->getClientIp()]);

            return new JsonResponse(['error' => 'Invalid API Key'], Response::HTTP_UNAUTHORIZED);
        } catch (OperationNotGrantedException $e) {
            // Valid key, insufficient grant — a config gap, not key guessing; no limiter hit.
            Log::warning('Databridge auth: operation not granted', ['user' => $e->userName, 'operation' => $e->operation->value]);

            return new JsonResponse(['error' => 'Operation not permitted for this API key'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $user);

        return $next($request);
    }

    /**
     * Maximum failed attempts per IP before returning 429, from
     * LEAN_DATABRIDGE_RATELIMIT_ATTEMPTS (default 20).
     */
    private function maxFailedAttempts(): int
    {
        return $this->positiveIntEnv('LEAN_DATABRIDGE_RATELIMIT_ATTEMPTS', self::DEFAULT_MAX_FAILED_ATTEMPTS);
    }

    /**
     * Window in seconds over which failed attempts are counted, from
     * LEAN_DATABRIDGE_RATELIMIT_DECAY (default 60).
     */
    private function decaySeconds(): int
    {
        return $this->positiveIntEnv('LEAN_DATABRIDGE_RATELIMIT_DECAY', self::DEFAULT_FAILED_ATTEMPT_DECAY_SECONDS);
    }

    /**
     * Read a positive integer from the environment, falling back to $default when the value
     * is unset or not a positive integer. env() (not config()) matches the plugin's other
     * settings; a misconfigured value must never disable or invert throttling — e.g. a 0
     * would make every request exceed the limit.
     */
    private function positiveIntEnv(string $key, int $default): int
    {
        $value = env($key);

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $default;
    }
}
