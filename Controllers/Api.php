<?php

namespace Leantime\Plugins\Databridge\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Model\ResponseData;
use Leantime\Plugins\Databridge\Services\Databridge;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API Controller for the Databridge plugin.
 */
class Api extends Controller
{
    private Databridge $databridgeService;

    /**
     * Initialize the controller with dependencies.
     */
    public function init(Databridge $databridgeService): void
    {
        $this->databridgeService = $databridgeService;
    }

    /**
     * Get tickets filtered by username with optional date range, scoped to the API
     * user's granted projects.
     *
     * $apiUser is deliberately non-nullable: a route wired without ApiKeyAuth fails
     * loudly instead of silently serving all projects.
     */
    public function tickets(array $input, ApiUser $apiUser): JsonResponse
    {
        $username = trim($input['username'] ?? '');

        if ('' === $username) {
            return new JsonResponse(
                ['error' => 'The "username" parameter is required.'],
                400,
            );
        }

        $start = (int) ($input['start'] ?? 0);
        $limit = (int) ($input['limit'] ?? 100);
        $dateFrom = $input['dateFrom'] ?? null;
        $dateTo = $input['dateTo'] ?? null;
        $status = isset($input['status']) ? trim($input['status']) : null;
        $status = '' !== $status ? $status : null;

        $results = $this->databridgeService->getTickets($username, $start, $limit, $dateFrom, $dateTo, $status, $apiUser->projects);

        return new JsonResponse(
            (new ResponseData(
                [
                    'username' => $username,
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'status' => $status,
                    'start' => $start,
                    'limit' => $limit,
                ],
                count($results),
                $results,
            ))->toArray(),
        );
    }
}
