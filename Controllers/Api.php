<?php

namespace Leantime\Plugins\Databridge\Controllers;

use Carbon\CarbonImmutable;
use Leantime\Core\Controller\Controller;
use Leantime\Plugins\Databridge\Exceptions\InvalidInputException;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Model\CreateTicketData;
use Leantime\Plugins\Databridge\Model\ResponseData;
use Leantime\Plugins\Databridge\Services\Databridge;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API Controller for the Databridge plugin.
 */
class Api extends Controller
{
    private const STATUS_TYPES = ['NEW', 'INPROGRESS', 'DONE'];

    /**
     * Byte size of the zp_tickets.description TEXT column. Exceeding it would be a DB
     * error (strict SQL mode) or silent truncation (non-strict), so reject explicitly.
     */
    private const MAX_DESCRIPTION_BYTES = 65535;

    private const DEFAULT_MAX_PLANNED_HOURS = 100.0;

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

        $sinceId = (int) ($input['sinceId'] ?? 0);
        $limit = (int) ($input['limit'] ?? 100);
        $dateFrom = $input['dateFrom'] ?? null;
        $dateTo = $input['dateTo'] ?? null;
        $status = isset($input['status']) ? trim($input['status']) : null;
        $status = '' !== $status ? $status : null;

        $results = $this->databridgeService->getTickets($username, $sinceId, $limit, $dateFrom, $dateTo, $status, $apiUser->projects);

        return new JsonResponse(
            (new ResponseData(
                [
                    'username' => $username,
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'status' => $status,
                    'sinceId' => $sinceId,
                    'limit' => $limit,
                ],
                count($results),
                $results,
            ))->toArray(),
        );
    }

    /**
     * Create a ticket in a granted project, assigned to the given username.
     *
     * $apiUser is deliberately non-nullable (see tickets()). The project grant is checked
     * BEFORE any DB-dependent check so an ungranted key cannot probe which project IDs
     * exist; the service re-asserts the grant fail-loud.
     */
    public function createTicket(array $input, ApiUser $apiUser): JsonResponse
    {
        if ([] === $input) {
            return new JsonResponse(['error' => 'Request body must be a non-empty JSON object.'], 400);
        }

        try {
            $projectId = $this->validateProjectId($input);

            // Grant check before existence: an ungranted key must not be able to probe project IDs.
            if (! $apiUser->canAccessProject($projectId)) {
                return new JsonResponse(['error' => 'Project not granted for this API key.'], 403);
            }

            $name = $this->validateName($input);
            $username = $this->validateUsername($input);
            $description = $this->validateDescription($input);
            $tags = $this->validateTags($input);
            $plannedHours = $this->validatePlannedHours($input);
            $dueDate = $this->validateDueDate($input);
            $statusType = $this->validateStatusType($input);
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $project = $this->databridgeService->findProject($projectId);
        if (null === $project) {
            return new JsonResponse(['error' => 'Unknown "projectId".'], 400);
        }

        // State -1 is "Closed" in the project-settings UI (the projects board calls it
        // "archive" — same value). Core hides it from every listing, so a ticket created
        // there would be invisible.
        if (-1 === (int) $project->state) {
            return new JsonResponse(['error' => 'The given project is closed.'], 400);
        }

        $assigneeId = $this->databridgeService->findUserIdByUsername($username);
        if (null === $assigneeId) {
            return new JsonResponse(['error' => 'Unknown "username".'], 400);
        }

        // A ticket assigned to someone who cannot access its project would be invisible
        // to them; reject it like the core UI does (assignee dropdown = project users).
        if (! $this->databridgeService->isUserAssignedToProject($assigneeId, $projectId)) {
            return new JsonResponse(['error' => 'The "username" user does not have access to the given project.'], 400);
        }

        $statusId = $this->databridgeService->resolveStatusIdForCreate($projectId, $statusType);
        if (null === $statusId) {
            return new JsonResponse(['error' => sprintf('No status of type "%s" is configured for this project.', $statusType)], 400);
        }

        $ticket = $this->databridgeService->createTicket(
            $apiUser,
            new CreateTicketData($projectId, $assigneeId, $name, $description, $dueDate, $tags, $plannedHours, $statusId),
        );

        return new JsonResponse(
            (new ResponseData(
                [
                    'projectId' => $projectId,
                    'username' => $username,
                    'name' => $name,
                    'description' => $description,
                    'dueDate' => $input['dueDate'] ?? null,
                    'tags' => $tags,
                    'plannedHours' => $plannedHours,
                    'status' => $statusType,
                ],
                1,
                [$ticket],
            ))->toArray(),
            201,
        );
    }

    /**
     * Validate and normalize the "projectId" field: a positive int, or a positive
     * digit-string (mirrors ApiUsers::parseProjectId tolerance).
     *
     * @throws InvalidInputException
     */
    private function validateProjectId(array $input): int
    {
        $value = $input['projectId'] ?? null;

        if (! $this->isPositiveIntLike($value)) {
            throw new InvalidInputException('The "projectId" field is required and must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * Validate and normalize the required "name" field (the ticket headline).
     *
     * @throws InvalidInputException
     */
    private function validateName(array $input): string
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';

        if ('' === $name) {
            throw new InvalidInputException('The "name" field is required.');
        }

        if (mb_strlen($name) > 255) {
            throw new InvalidInputException('The "name" field must not exceed 255 characters.');
        }

        return $name;
    }

    /**
     * Validate and normalize the required "username" field (the assignee email).
     *
     * @throws InvalidInputException
     */
    private function validateUsername(array $input): string
    {
        $username = is_string($input['username'] ?? null) ? trim($input['username']) : '';

        if ('' === $username) {
            throw new InvalidInputException('The "username" field is required.');
        }

        return $username;
    }

    /**
     * Validate the optional "description" field.
     *
     * @throws InvalidInputException
     */
    private function validateDescription(array $input): ?string
    {
        if (! isset($input['description'])) {
            return null;
        }

        if (! is_string($input['description'])) {
            throw new InvalidInputException('The "description" field must be a string.');
        }

        // strlen, not mb_strlen: the TEXT column limit is bytes, not characters.
        if (strlen($input['description']) > self::MAX_DESCRIPTION_BYTES) {
            throw new InvalidInputException(sprintf('The "description" field must not exceed %d bytes.', self::MAX_DESCRIPTION_BYTES));
        }

        return $input['description'];
    }

    /**
     * Validate and normalize the optional "tags" field into a deduplicated list of
     * clean tag strings. Commas are rejected because the DB column stores tags
     * comma-separated.
     *
     * @return string[]
     *
     * @throws InvalidInputException
     */
    private function validateTags(array $input): array
    {
        if (! isset($input['tags'])) {
            return [];
        }

        $invalidMessage = 'The "tags" field must be an array of non-empty strings without commas.';

        if (! is_array($input['tags'])) {
            throw new InvalidInputException($invalidMessage);
        }

        $tags = [];
        foreach ($input['tags'] as $tag) {
            if (! is_string($tag)) {
                throw new InvalidInputException($invalidMessage);
            }

            $tag = trim($tag);
            if ('' === $tag || str_contains($tag, ',')) {
                throw new InvalidInputException($invalidMessage);
            }

            if (! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        if (mb_strlen(implode(',', $tags)) > 255) {
            throw new InvalidInputException('The combined "tags" value must not exceed 255 characters.');
        }

        return $tags;
    }

    /**
     * Validate and normalize the optional "plannedHours" field. The upper limit also
     * rejects non-finite values (e.g. a JSON 1e999, which decodes to INF).
     *
     * @throws InvalidInputException
     */
    private function validatePlannedHours(array $input): ?float
    {
        if (! isset($input['plannedHours'])) {
            return null;
        }

        $max = $this->maxPlannedHours();
        $value = $input['plannedHours'];

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > $max) {
            throw new InvalidInputException(sprintf('The "plannedHours" field must be a number between 0 and %s.', $max));
        }

        return (float) $value;
    }

    /**
     * Validate and parse the optional "dueDate" field.
     *
     * @throws InvalidInputException
     */
    private function validateDueDate(array $input): ?CarbonImmutable
    {
        if (! isset($input['dueDate'])) {
            return null;
        }

        $dueDate = is_string($input['dueDate']) ? $this->databridgeService->parseDueDate($input['dueDate']) : null;

        if (null === $dueDate) {
            throw new InvalidInputException('The "dueDate" field must be a valid date in "Y-m-d" or "Y-m-d H:i:s" format (UTC).');
        }

        return $dueDate;
    }

    /**
     * Validate and normalize the optional "status" field to an uppercase status type.
     * Only the format is checked here; resolution to a per-project status int happens
     * after project existence is confirmed.
     *
     * @throws InvalidInputException
     */
    private function validateStatusType(array $input): ?string
    {
        if (! isset($input['status'])) {
            return null;
        }

        $statusType = is_string($input['status']) ? strtoupper(trim($input['status'])) : '';

        if (! in_array($statusType, self::STATUS_TYPES, true)) {
            throw new InvalidInputException('The "status" field must be one of NEW, INPROGRESS, DONE.');
        }

        return $statusType;
    }

    /**
     * Upper limit for the "plannedHours" field, overridable via the
     * LEAN_DATABRIDGE_MAX_PLANNED_HOURS env variable. A non-positive, non-finite, or
     * non-numeric value falls back to the default. Read via env() (not config()) because
     * plugins cannot extend Leantime's custom config map.
     */
    private function maxPlannedHours(): float
    {
        $value = env('LEAN_DATABRIDGE_MAX_PLANNED_HOURS');

        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0
            ? (float) $value
            : self::DEFAULT_MAX_PLANNED_HOURS;
    }

    /**
     * Whether a value is a positive integer or a positive integer-like string.
     */
    private function isPositiveIntLike(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }
}
