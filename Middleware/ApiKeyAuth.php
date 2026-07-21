<?php

namespace Leantime\Plugins\Databridge\Middleware;

use Closure;
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

    public function __construct(private readonly ApiUsers $apiUsers) {}

    /**
     * Authenticate the API key header and authorize the required operation.
     *
     * @param  string  $operation  Backed value of a Model\Operation case, declared on the route.
     */
    public function handle(IncomingRequest $request, Closure $next, string $operation): Response
    {
        // A typo in routes.php is a developer error: from() throws, failing loudly with a 500.
        $requiredOperation = Operation::from($operation);

        try {
            $user = $this->apiUsers->authenticate($request->headers->get(self::HEADER));
            $this->apiUsers->authorize($user, $requiredOperation);
        } catch (InvalidApiKeyException) {
            Log::info('Databridge auth: rejected request with missing or unknown API key', ['ip' => $request->getClientIp()]);

            return new JsonResponse(['error' => 'Invalid API Key'], Response::HTTP_UNAUTHORIZED);
        } catch (OperationNotGrantedException $e) {
            Log::info('Databridge auth: operation not granted', ['user' => $e->userName, 'operation' => $e->operation->value]);

            return new JsonResponse(['error' => 'Operation not permitted for this API key'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $user);

        return $next($request);
    }
}
