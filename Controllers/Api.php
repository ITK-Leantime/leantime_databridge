<?php

namespace Leantime\Plugins\Databridge\Controllers;

use Carbon\CarbonImmutable;
use Leantime\Core\Controller\Controller;
use Leantime\Plugins\Databridge\Exceptions\DuplicateEntryException;
use Leantime\Plugins\Databridge\Exceptions\InvalidInputException;
use Leantime\Plugins\Databridge\Exceptions\ResourceNotAccessibleException;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Model\CreateCommentData;
use Leantime\Plugins\Databridge\Model\CreateTicketData;
use Leantime\Plugins\Databridge\Model\CreateTimesheetData;
use Leantime\Plugins\Databridge\Model\ResponseData;
use Leantime\Plugins\Databridge\Model\TicketData;
use Leantime\Plugins\Databridge\Model\UpdateTicketData;
use Leantime\Plugins\Databridge\Services\Databridge;
use Leantime\Plugins\Databridge\Utils\PositiveInt;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API Controller for the Databridge plugin.
 *
 * Endpoint methods return the response for anything the caller can act on (bad input,
 * conflicts) and throw ResourceNotAccessibleException when a named project or ticket is
 * outside the key's grant. That one renders itself, so the access decision cannot be
 * mistaken for an ordinary result — or silently dropped by a caller that forgot to check
 * a return value.
 */
class Api extends Controller
{
    /**
     * Byte size of the zp_tickets.description TEXT column. Exceeding it would be a DB
     * error (strict SQL mode) or silent truncation (non-strict), so reject explicitly.
     */
    private const MAX_DESCRIPTION_BYTES = 65535;

    private const DEFAULT_MAX_PLANNED_HOURS = 100.0;

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Byte size of the zp_comment.text and zp_timesheets.description TEXT columns, same
     * rationale as MAX_DESCRIPTION_BYTES.
     */
    private const MAX_TEXT_BYTES = 65535;

    /**
     * Status types a client may set on a ticket. -1 (archived) is intentionally unreachable:
     * it maps to the DONE type but archiving is a separate, destructive-feeling action that
     * this API does not expose.
     */
    private const SETTABLE_STATUS_TYPES = ['NEW', 'INPROGRESS', 'DONE'];

    /**
     * Booking kinds accepted for a time entry, mirroring core's
     * \Leantime\Domain\Timesheets\Repositories\Timesheets::$kind. Duplicated rather than read
     * from core because the property is a plain array with no accessor, and an unknown value
     * would make an entry that core's timesheet filters cannot show.
     */
    private const TIMESHEET_KINDS = [
        'GENERAL_BILLABLE',
        'GENERAL_NOT_BILLABLE',
        'PROJECTMANAGEMENT',
        'DEVELOPMENT',
        'BUGFIXING_NOT_BILLABLE',
        'TESTING',
    ];

    private const DEFAULT_TIMESHEET_KIND = 'GENERAL_BILLABLE';

    /**
     * Upper limit for a single time entry. A day has 24 hours; anything above is a typo
     * (and, like plannedHours, this also rejects a JSON 1e999 decoding to INF).
     */
    private const MAX_TIMESHEET_HOURS = 24.0;

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
     * List the projects the API key may access.
     *
     * Takes no filter parameters: the key's grant is the filter. A grant listing a
     * nonexistent project ID simply yields nothing for that ID, as on the ticket endpoints.
     */
    public function projects(ApiUser $apiUser): JsonResponse
    {
        $results = $this->databridgeService->getProjects($apiUser->projects);

        return new JsonResponse(
            (new ResponseData([], count($results), $results))->toArray(),
        );
    }

    /**
     * List the active users assigned to projects the API key may access.
     *
     * Exists so a client can resolve a person to the username the ticket and timesheet
     * endpoints take: those identify a user by username with no way to discover one.
     *
     * An optional projectId narrows the list, and is grant-checked like every other
     * project-scoped endpoint — without that check a key could name any project and learn
     * who is on it.
     */
    public function users(array $input, ApiUser $apiUser): JsonResponse
    {
        $projectId = isset($input['projectId']) && '' !== $input['projectId']
            ? (int) $input['projectId']
            : null;

        if (null !== $projectId) {
            $this->assertProjectGranted($projectId, $apiUser);
        }

        $results = $this->databridgeService->getUsers($projectId, $apiUser->projects);

        return new JsonResponse(
            (new ResponseData(
                null !== $projectId ? ['projectId' => $projectId] : [],
                count($results),
                $results,
            ))->toArray(),
        );
    }

    /**
     * Get a project's progress (percent complete and core's completion estimate).
     */
    public function projectProgress(int $projectId, ApiUser $apiUser): JsonResponse
    {
        $this->assertProjectGranted($projectId, $apiUser);

        $progress = $this->databridgeService->getProjectProgress($projectId);

        return new JsonResponse(
            (new ResponseData(['projectId' => $projectId], 1, [$progress]))->toArray(),
        );
    }

    /**
     * Get a project's status scheme, so a client can map a statusType to that project's own
     * status int rather than hardcoding one.
     */
    public function projectStatuses(int $projectId, ApiUser $apiUser): JsonResponse
    {
        $this->assertProjectGranted($projectId, $apiUser);

        $results = $this->databridgeService->getProjectStatuses($projectId);

        return new JsonResponse(
            (new ResponseData(['projectId' => $projectId], count($results), $results))->toArray(),
        );
    }

    /**
     * Get a single ticket by ID.
     */
    public function ticket(int $ticketId, ApiUser $apiUser): JsonResponse
    {
        $ticket = $this->requireAccessibleTicket($ticketId, $apiUser);

        return new JsonResponse(
            (new ResponseData(['id' => $ticketId], 1, [$ticket]))->toArray(),
        );
    }

    /**
     * List milestones in the granted projects, optionally narrowed to one project.
     */
    public function milestones(array $input, ApiUser $apiUser): JsonResponse
    {
        try {
            $projectId = PositiveInt::optionalField($input, 'projectId');
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if (null !== $projectId) {
            $this->assertProjectGranted($projectId, $apiUser);
        }

        $results = $this->databridgeService->getMilestones($projectId, $apiUser->projects);

        return new JsonResponse(
            (new ResponseData(['projectId' => $projectId], count($results), $results))->toArray(),
        );
    }

    /**
     * List logged time entries for a ticket or a project.
     *
     * Exactly one of ticketId/projectId is required: without a filter this would stream every
     * time entry in every granted project, which is never what a caller wants and is
     * expensive on a real installation.
     */
    public function timesheets(array $input, ApiUser $apiUser): JsonResponse
    {
        try {
            $ticketId = PositiveInt::optionalField($input, 'ticketId');
            $projectId = PositiveInt::optionalField($input, 'projectId');
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if ((null === $ticketId) === (null === $projectId)) {
            return new JsonResponse(['error' => 'Exactly one of "ticketId" or "projectId" is required.'], 400);
        }

        if (null !== $projectId) {
            $this->assertProjectGranted($projectId, $apiUser);
        }

        if (null !== $ticketId) {
            $this->requireAccessibleTicket($ticketId, $apiUser);
        }

        $results = $this->databridgeService->getTimesheets($ticketId, $projectId, $apiUser->projects);

        return new JsonResponse(
            (new ResponseData(['ticketId' => $ticketId, 'projectId' => $projectId], count($results), $results))->toArray(),
        );
    }

    /**
     * List a ticket's comments.
     */
    public function ticketComments(int $ticketId, ApiUser $apiUser): JsonResponse
    {
        $this->requireAccessibleTicket($ticketId, $apiUser);

        $results = $this->databridgeService->getTicketComments($ticketId);

        return new JsonResponse(
            (new ResponseData(['ticketId' => $ticketId], count($results), $results))->toArray(),
        );
    }

    /**
     * List metadata for a ticket's attached files.
     *
     * Metadata only — file contents are never served by this API.
     */
    public function ticketFiles(int $ticketId, ApiUser $apiUser): JsonResponse
    {
        $this->requireAccessibleTicket($ticketId, $apiUser);

        $results = $this->databridgeService->getTicketFiles($ticketId);

        return new JsonResponse(
            (new ResponseData(['ticketId' => $ticketId], count($results), $results))->toArray(),
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
            $projectId = PositiveInt::requiredField($input, 'projectId');

            // Grant check before existence: an ungranted key must not be able to probe project IDs.
            $this->assertProjectGranted($projectId, $apiUser);

            $name = $this->validateName($input);
            $username = $this->validateUsername($input);
            $description = $this->validateDescription($input);
            $tags = $this->validateTags($input);
            $plannedHours = $this->validatePlannedHours($input);
            $dueDate = $this->validateDueDate($input);
            $milestoneId = PositiveInt::optionalField($input, 'milestoneId');
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

        // One message for nonexistent / other-project / not-a-milestone ids: within the
        // granted project there is nothing to distinguish, and outside it nothing to leak.
        if (null !== $milestoneId && ! $this->databridgeService->milestoneExistsInProject($milestoneId, $projectId)) {
            return new JsonResponse(['error' => 'Unknown "milestoneId" for the given project.'], 400);
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

        $statusId = $this->databridgeService->resolveNewStatusId($projectId);

        $ticket = $this->databridgeService->createTicket(
            $apiUser,
            new CreateTicketData($projectId, $assigneeId, $name, $description, $dueDate, $tags, $plannedHours, $milestoneId, $statusId),
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
                    'milestoneId' => $milestoneId,
                ],
                1,
                [$ticket],
            ))->toArray(),
            201,
        );
    }

    /**
     * Apply a partial update to a ticket. Only the fields present in the body are written;
     * absent fields keep their value and are never nulled.
     */
    public function updateTicket(int $ticketId, array $input, ApiUser $apiUser): JsonResponse
    {
        if ([] === $input) {
            return new JsonResponse(['error' => 'Request body must be a non-empty JSON object.'], 400);
        }

        $ticket = $this->requireAccessibleTicket($ticketId, $apiUser);

        try {
            $columns = $this->buildTicketUpdateColumns($input, $ticket->projectId);
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if ([] === $columns) {
            return new JsonResponse(['error' => 'Request body must contain at least one updatable field.'], 400);
        }

        $updated = $this->databridgeService->updateTicket(
            $apiUser,
            new UpdateTicketData($ticketId, $ticket->projectId, $columns),
        );

        return new JsonResponse(
            (new ResponseData(['id' => $ticketId, 'fields' => array_keys($input)], 1, [$updated]))->toArray(),
        );
    }

    /**
     * Log time on a ticket in a granted project.
     */
    public function createTimesheet(array $input, ApiUser $apiUser): JsonResponse
    {
        if ([] === $input) {
            return new JsonResponse(['error' => 'Request body must be a non-empty JSON object.'], 400);
        }

        try {
            $ticketId = PositiveInt::requiredField($input, 'ticketId');
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $ticket = $this->requireAccessibleTicket($ticketId, $apiUser);

        try {
            $username = $this->validateUsername($input);
            $hours = $this->validateHours($input);
            $workDate = $this->validateWorkDate($input);
            $description = $this->validateText($input, 'description', false);
            $kind = $this->validateKind($input);
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $userId = $this->databridgeService->findUserIdByUsername($username);
        if (null === $userId) {
            return new JsonResponse(['error' => 'Unknown "username".'], 400);
        }

        try {
            $timesheet = $this->databridgeService->createTimesheet(
                $apiUser,
                new CreateTimesheetData($ticketId, $ticket->projectId, $userId, $workDate, $hours, $description, $kind),
            );
        } catch (DuplicateEntryException $e) {
            // 409 rather than 400: the request is well-formed, the entry simply already exists.
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        return new JsonResponse(
            (new ResponseData(
                [
                    'ticketId' => $ticketId,
                    'username' => $username,
                    'hours' => $hours,
                    'workDate' => $input['workDate'] ?? null,
                    'kind' => $kind,
                ],
                1,
                [$timesheet],
            ))->toArray(),
            201,
        );
    }

    /**
     * Add a comment to a ticket in a granted project.
     *
     * The author comes from the "username" field: an API-key request has no session user, so
     * without it every comment would be attributed to nobody.
     */
    public function createTicketComment(int $ticketId, array $input, ApiUser $apiUser): JsonResponse
    {
        if ([] === $input) {
            return new JsonResponse(['error' => 'Request body must be a non-empty JSON object.'], 400);
        }

        $ticket = $this->requireAccessibleTicket($ticketId, $apiUser);

        try {
            $username = $this->validateUsername($input);
            $text = $this->validateText($input, 'text', true);
        } catch (InvalidInputException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $userId = $this->databridgeService->findUserIdByUsername($username);
        if (null === $userId) {
            return new JsonResponse(['error' => 'Unknown "username".'], 400);
        }

        $comment = $this->databridgeService->createTicketComment(
            $apiUser,
            new CreateCommentData($ticketId, $ticket->projectId, $userId, $text),
        );

        return new JsonResponse(
            (new ResponseData(['ticketId' => $ticketId, 'username' => $username], 1, [$comment]))->toArray(),
            201,
        );
    }

    /**
     * Guard a project-scoped endpoint: returns when the project is granted, throws when it
     * is not. Deliberately does not check existence — a nonexistent granted ID yields empty
     * results, exactly as an ungranted-but-real one is refused, so neither answer reveals
     * which project IDs exist.
     *
     * @throws ResourceNotAccessibleException
     */
    private function assertProjectGranted(int $projectId, ApiUser $apiUser): void
    {
        if (! $apiUser->canAccessProject($projectId)) {
            throw ResourceNotAccessibleException::projectNotGranted($projectId, $apiUser->name);
        }
    }

    /**
     * Guard a ticket-scoped endpoint and hand back the ticket, so callers that need its
     * projectId do not look it up twice. Throws when the ticket does not exist or lives
     * outside the grant — both cases answer with the same 404, see the exception.
     *
     * @throws ResourceNotAccessibleException
     */
    private function requireAccessibleTicket(int $ticketId, ApiUser $apiUser): TicketData
    {
        $ticket = $this->databridgeService->getTicket($ticketId);

        if (null === $ticket || ! $apiUser->canAccessProject($ticket->projectId)) {
            throw ResourceNotAccessibleException::ticketNotAccessible();
        }

        return $ticket;
    }

    /**
     * Map the provided PATCH fields to zp_tickets columns, validating each.
     *
     * Only keys actually present in the body end up in the result, which is what makes the
     * update partial: an absent field produces no column and is therefore left untouched.
     * Unknown keys are rejected rather than ignored, so a typo ("titel") fails loudly
     * instead of silently doing nothing.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidInputException
     */
    private function buildTicketUpdateColumns(array $input, int $projectId): array
    {
        $known = ['name', 'description', 'dueDate', 'plannedHours', 'remainingHours', 'tags', 'milestoneId', 'assignee', 'status'];
        $unknown = array_diff(array_keys($input), $known);

        if ([] !== $unknown) {
            throw new InvalidInputException(sprintf('Unknown field(s): %s. Updatable fields are: %s.', implode(', ', $unknown), implode(', ', $known)));
        }

        $columns = [];

        if (array_key_exists('name', $input)) {
            $columns['headline'] = $this->validateName($input);
        }

        // An explicit null on a nullable field clears it, and is written as null — never ''.
        // The float and datetime columns would reject '' under strict SQL mode.
        if (array_key_exists('description', $input)) {
            $columns['description'] = $this->validateDescription($input);
        }

        if (array_key_exists('dueDate', $input)) {
            $dueDate = $this->validateDueDate($input);
            $columns['dateToFinish'] = $dueDate?->format(self::DATE_FORMAT);
        }

        if (array_key_exists('plannedHours', $input)) {
            $columns['planHours'] = $this->validatePlannedHours($input);
        }

        if (array_key_exists('remainingHours', $input)) {
            $columns['hourRemaining'] = $this->validateRemainingHours($input);
        }

        if (array_key_exists('tags', $input)) {
            $tags = $this->validateTags($input);
            $columns['tags'] = [] !== $tags ? implode(',', $tags) : null;
        }

        if (array_key_exists('milestoneId', $input)) {
            $columns['milestoneid'] = $this->validateMilestoneId($input, $projectId);
        }

        if (array_key_exists('assignee', $input)) {
            $assigneeId = $this->validateAssignee($input, $projectId);
            $columns['userId'] = $assigneeId;
            // editorId is a varchar(75) column that stores the assignee's user id as a string.
            $columns['editorId'] = (string) $assigneeId;
        }

        if (array_key_exists('status', $input)) {
            $columns['status'] = $this->validateStatus($input, $projectId);
        }

        return $columns;
    }

    /**
     * Validate the optional "remainingHours" field, allowing an explicit null to clear it.
     *
     * @throws InvalidInputException
     */
    private function validateRemainingHours(array $input): ?float
    {
        if (null === $input['remainingHours']) {
            return null;
        }

        $max = $this->maxPlannedHours();
        $value = $input['remainingHours'];

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > $max) {
            throw new InvalidInputException(sprintf('The "remainingHours" field must be a number between 0 and %s, or null.', $max));
        }

        return (float) $value;
    }

    /**
     * Validate the "milestoneId" field of a PATCH, allowing an explicit null to detach the
     * ticket from its milestone.
     *
     * @throws InvalidInputException
     */
    private function validateMilestoneId(array $input, int $projectId): ?int
    {
        if (null === $input['milestoneId']) {
            return null;
        }

        $milestoneId = PositiveInt::optionalField($input, 'milestoneId');

        // One message for nonexistent / other-project / not-a-milestone ids: within the
        // granted project there is nothing to distinguish, and outside it nothing to leak.
        if (! $this->databridgeService->milestoneExistsInProject($milestoneId, $projectId)) {
            throw new InvalidInputException('Unknown "milestoneId" for the given project.');
        }

        return $milestoneId;
    }

    /**
     * Resolve the "assignee" username to a zp_user id, rejecting users who cannot access the
     * ticket's project — a ticket assigned to them would be invisible to them.
     *
     * @throws InvalidInputException
     */
    private function validateAssignee(array $input, int $projectId): int
    {
        $username = is_string($input['assignee'] ?? null) ? trim($input['assignee']) : '';

        if ('' === $username) {
            throw new InvalidInputException('The "assignee" field must be a non-empty username.');
        }

        $assigneeId = $this->databridgeService->findUserIdByUsername($username);

        if (null === $assigneeId) {
            throw new InvalidInputException('Unknown "assignee".');
        }

        if (! $this->databridgeService->isUserAssignedToProject($assigneeId, $projectId)) {
            throw new InvalidInputException('The "assignee" user does not have access to the ticket\'s project.');
        }

        return $assigneeId;
    }

    /**
     * Resolve the "status" field's status type to a concrete status int for the ticket's
     * project. Status ints are per-project and their labels are user-editable, so the API
     * takes the stable type and never a raw int.
     *
     * @throws InvalidInputException
     */
    private function validateStatus(array $input, int $projectId): int
    {
        $statusType = is_string($input['status'] ?? null) ? strtoupper(trim($input['status'])) : '';

        if (! in_array($statusType, self::SETTABLE_STATUS_TYPES, true)) {
            throw new InvalidInputException(sprintf('The "status" field must be one of: %s (case-insensitive).', implode(', ', self::SETTABLE_STATUS_TYPES)));
        }

        $statusId = $this->databridgeService->resolveStatusIdForProject($projectId, $statusType);

        if (null === $statusId) {
            throw new InvalidInputException(sprintf('The ticket\'s project has no status of type "%s".', $statusType));
        }

        return $statusId;
    }

    /**
     * Validate the required "hours" field of a time entry.
     *
     * Zero is rejected: an entry of no hours records nothing and only pollutes reports.
     *
     * @throws InvalidInputException
     */
    private function validateHours(array $input): float
    {
        $value = $input['hours'] ?? null;

        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > self::MAX_TIMESHEET_HOURS) {
            throw new InvalidInputException(sprintf('The "hours" field is required and must be a number greater than 0 and at most %s.', self::MAX_TIMESHEET_HOURS));
        }

        return (float) $value;
    }

    /**
     * Validate and parse the required "workDate" field, reusing the dueDate parser: the
     * accepted formats and the UTC assumption are the same.
     *
     * @throws InvalidInputException
     */
    private function validateWorkDate(array $input): CarbonImmutable
    {
        $workDate = is_string($input['workDate'] ?? null) ? $this->databridgeService->parseDueDate($input['workDate']) : null;

        if (null === $workDate) {
            throw new InvalidInputException('The "workDate" field is required and must be a valid date in "Y-m-d" or "Y-m-d H:i:s" format (UTC).');
        }

        return $workDate;
    }

    /**
     * Validate a free-text TEXT-column field.
     *
     * @param  bool  $required  When true an absent or blank value is rejected; when false an
     *                          absent value yields null.
     *
     * @throws InvalidInputException
     */
    private function validateText(array $input, string $field, bool $required): ?string
    {
        if (! isset($input[$field])) {
            if ($required) {
                throw new InvalidInputException(sprintf('The "%s" field is required.', $field));
            }

            return null;
        }

        if (! is_string($input[$field])) {
            throw new InvalidInputException(sprintf('The "%s" field must be a string.', $field));
        }

        if ($required && '' === trim($input[$field])) {
            throw new InvalidInputException(sprintf('The "%s" field must not be empty.', $field));
        }

        // strlen, not mb_strlen: the TEXT column limit is bytes, not characters.
        if (strlen($input[$field]) > self::MAX_TEXT_BYTES) {
            throw new InvalidInputException(sprintf('The "%s" field must not exceed %d bytes.', $field, self::MAX_TEXT_BYTES));
        }

        return $input[$field];
    }

    /**
     * Validate the optional "kind" of a time entry, defaulting to core's GENERAL_BILLABLE.
     *
     * @throws InvalidInputException
     */
    private function validateKind(array $input): string
    {
        if (! isset($input['kind'])) {
            return self::DEFAULT_TIMESHEET_KIND;
        }

        $kind = is_string($input['kind']) ? strtoupper(trim($input['kind'])) : '';

        if (! in_array($kind, self::TIMESHEET_KINDS, true)) {
            throw new InvalidInputException(sprintf('The "kind" field must be one of: %s (case-insensitive).', implode(', ', self::TIMESHEET_KINDS)));
        }

        return $kind;
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
     * Validate the optional "description" field. Null when absent or explicitly null — on a
     * PATCH the latter clears the description.
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
     * rejects non-finite values (e.g. a JSON 1e999, which decodes to INF). Null when absent
     * or explicitly null — on a PATCH the latter clears the estimate.
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
     * Validate and parse the optional "dueDate" field. Null when absent or explicitly null —
     * on a PATCH the latter clears the due date.
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
}
