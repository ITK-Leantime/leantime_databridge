<?php

namespace Leantime\Plugins\Databridge\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Leantime\Plugins\Databridge\Exceptions\DuplicateEntryException;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Projects\Services\Projects as ProjectService;
use Leantime\Domain\Tickets\Repositories\Tickets as TicketRepository;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Model\CommentData;
use Leantime\Plugins\Databridge\Model\CreateCommentData;
use Leantime\Plugins\Databridge\Model\CreateTicketData;
use Leantime\Plugins\Databridge\Model\CreateTimesheetData;
use Leantime\Plugins\Databridge\Model\FileData;
use Leantime\Plugins\Databridge\Model\MilestoneData;
use Leantime\Plugins\Databridge\Model\ProjectData;
use Leantime\Plugins\Databridge\Model\ProjectProgressData;
use Leantime\Plugins\Databridge\Model\StatusData;
use Leantime\Plugins\Databridge\Model\TicketData;
use Leantime\Plugins\Databridge\Model\TimesheetData;
use Leantime\Plugins\Databridge\Model\UpdateTicketData;
use Leantime\Plugins\Databridge\Model\UserData;
use Leantime\Plugins\Databridge\Repositories\DatabridgeRepository;

/**
 * Service layer for the Databridge plugin.
 */
class Databridge
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private const DUE_DATE_INPUT_FORMATS = [self::DATE_FORMAT, 'Y-m-d'];

    /**
     * Core's seed id for a "new" ticket status, used as a fallback when a project has no
     * NEW-typed status configured. The id comes from the default status scheme in
     * \Leantime\Domain\Tickets\Repositories\Tickets::$statusListSeed (3 => NEW), which is
     * also what core's own Tickets service falls back to on create ($values['status'] ?? 3).
     */
    private const DEFAULT_NEW_STATUS_ID = 3;

    /**
     * zp_projects.state value for a closed project ("Closed" in project settings; the
     * projects board calls the same state "archive"). Core hides those from every listing.
     */
    private const PROJECT_STATE_CLOSED = -1;

    public function __construct(
        private readonly DatabridgeRepository $repository,
        private readonly TicketRepository $ticketRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectService $projectService,
    ) {}

    /**
     * Get tickets for a given username with optional date and status filtering, scoped
     * to the API user's granted projects.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return TicketData[]
     */
    public function getTickets(string $username, int $sinceId, int $limit, ?string $dateFrom, ?string $dateTo, ?string $status, ?array $allowedProjects): array
    {
        $statusIds = null;
        if (null !== $status) {
            $statusIds = $this->resolveStatusIds($username, strtoupper($status), $allowedProjects);
            if (empty($statusIds)) {
                return [];
            }
        }

        $values = $this->repository->getTicketsByUsername($username, $sinceId, $limit, $dateFrom, $dateTo, $statusIds, $allowedProjects);

        return array_map(fn ($value) => $this->mapRowToTicketData($value), $values);
    }

    /**
     * Get a single ticket by ID, or null when it does not exist.
     *
     * Returns the ticket regardless of grant: the caller must check the project grant on the
     * returned projectId, because refusing here could not distinguish "not granted" from
     * "does not exist" and would leak which ticket IDs are real.
     */
    public function getTicket(int $ticketId): ?TicketData
    {
        $row = $this->repository->findTicketRowById($ticketId);

        return null !== $row ? $this->mapRowToTicketData($row) : null;
    }

    /**
     * Get the active users assigned to the projects the API user may access.
     *
     * @param  ?int  $projectId  Narrow to one project; null = every allowed project.
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return UserData[]
     */
    public function getUsers(?int $projectId, ?array $allowedProjects): array
    {
        return array_map(
            fn ($row) => new UserData(
                (int) $row->id,
                (string) $row->username,
                (string) $row->firstname,
                (string) $row->lastname,
                // Empty strings are the zp_user default for these, and an absent job title
                // is more usefully null than '' to a consumer.
                '' !== (string) $row->jobTitle ? (string) $row->jobTitle : null,
                '' !== (string) $row->department ? (string) $row->department : null,
                array_map('intval', explode(',', (string) $row->projectIds)),
            ),
            $this->repository->getUsers($projectId, $allowedProjects),
        );
    }

    /**
     * Get the projects the API user may access.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return ProjectData[]
     */
    public function getProjects(?array $allowedProjects): array
    {
        return array_map(
            fn ($row) => new ProjectData(
                (int) $row->id,
                (string) $row->name,
                null !== $row->state ? (int) $row->state : null,
                (int) $row->state === self::PROJECT_STATE_CLOSED,
            ),
            $this->repository->getProjects($allowedProjects),
        );
    }

    /**
     * Get a project's progress, reusing core's project service.
     *
     * Core embeds an HTML button in estimatedCompletionDate when it has too little data (or
     * when the project is complete), so tags are stripped and entities decoded — an API
     * consumer needs the sentence, not core's markup.
     */
    public function getProjectProgress(int $projectId): ProjectProgressData
    {
        $progress = $this->projectService->getProjectProgress($projectId);

        return new ProjectProgressData(
            $projectId,
            (float) $progress['percent'],
            trim(html_entity_decode(strip_tags($progress['estimatedCompletionDate']))),
            (string) $progress['plannedCompletionDate'],
        );
    }

    /**
     * Get a project's status scheme, so clients can map a statusType to the project's own
     * status int instead of hardcoding one.
     *
     * @return StatusData[]
     */
    public function getProjectStatuses(int $projectId): array
    {
        $statuses = [];

        foreach ($this->ticketRepository->getStateLabels($projectId) as $key => $label) {
            $statuses[] = new StatusData(
                (int) $key,
                (string) ($label['name'] ?? ''),
                $label['statusType'] ?? null,
                (bool) ($label['kanbanCol'] ?? false),
            );
        }

        return $statuses;
    }

    /**
     * Get milestones, optionally narrowed to one project and always scoped to the API user's
     * granted projects.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return MilestoneData[]
     */
    public function getMilestones(?int $projectId, ?array $allowedProjects): array
    {
        return array_map(
            fn ($row) => new MilestoneData(
                (int) $row->id,
                (int) $row->projectId,
                (string) $row->headline,
                (int) $row->status,
                $this->ticketRepository->getStateLabels((int) $row->projectId)[$row->status]['statusType'] ?? null,
                $this->getCarbonFromDatabaseValue($row->dateToFinish),
            ),
            $this->repository->getMilestones($projectId, $allowedProjects),
        );
    }

    /**
     * Get logged time entries for a ticket or a project, scoped to the API user's granted
     * projects.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return TimesheetData[]
     */
    public function getTimesheets(?int $ticketId, ?int $projectId, ?array $allowedProjects): array
    {
        return array_map(
            fn ($row) => $this->mapRowToTimesheetData($row),
            $this->repository->getTimesheets($ticketId, $projectId, $allowedProjects),
        );
    }

    /**
     * Get a ticket's comments. The caller owns the grant check on the ticket's project.
     *
     * @return CommentData[]
     */
    public function getTicketComments(int $ticketId): array
    {
        return array_map(
            fn ($row) => $this->mapRowToCommentData($row),
            $this->repository->getTicketComments($ticketId),
        );
    }

    /**
     * Get metadata for a ticket's attached files. The caller owns the grant check on the
     * ticket's project.
     *
     * Metadata only — file contents are never exposed by this API.
     *
     * @return FileData[]
     */
    public function getTicketFiles(int $ticketId): array
    {
        return array_map(
            fn ($row) => new FileData(
                (int) $row->id,
                (int) $row->moduleId,
                $row->realName,
                $row->extension,
                null !== $row->userId ? (int) $row->userId : null,
                $row->username,
                $this->getCarbonFromDatabaseValue($row->date),
            ),
            $this->repository->getTicketFiles($ticketId),
        );
    }

    /**
     * Map a ticket row (shape of getTicketsByUsername/findTicketRowById) to a TicketData model.
     */
    private function mapRowToTicketData(object $value): TicketData
    {
        $projectStatuses = $this->ticketRepository->getStateLabels($value->projectId);

        return new TicketData(
            $value->id,
            $value->projectId,
            $value->headline,
            $projectStatuses[$value->status]['statusType'] ?? null,
            $this->getMilestoneId($value),
            ! empty($value->tags) ? explode(',', $value->tags) : [],
            $value->username,
            $value->planHours,
            $value->hourRemaining,
            $this->getCarbonFromDatabaseValue($value->dateToFinish),
            $this->getCarbonFromDatabaseValue($value->editTo),
            $this->getCarbonFromDatabaseValue($value->modified),
        );
    }

    /**
     * Map a time-entry row (shape of getTimesheets/findTimesheetRowById) to a TimesheetData model.
     */
    private function mapRowToTimesheetData(object $row): TimesheetData
    {
        return new TimesheetData(
            (int) $row->id,
            (int) $row->ticketId,
            (int) $row->projectId,
            null !== $row->userId ? (int) $row->userId : null,
            $row->username,
            $this->getCarbonFromDatabaseValue($row->workDate),
            null !== $row->hours ? (float) $row->hours : null,
            $row->description,
            $row->kind,
        );
    }

    /**
     * Map a comment row (shape of getTicketComments/findCommentRowById) to a CommentData model.
     */
    private function mapRowToCommentData(object $row): CommentData
    {
        return new CommentData(
            (int) $row->id,
            (int) $row->moduleId,
            null !== $row->userId ? (int) $row->userId : null,
            $row->username,
            $this->getCarbonFromDatabaseValue($row->date),
            (string) $row->text,
        );
    }

    /**
     * Parse a database datetime string to CarbonImmutable.
     */
    private function getCarbonFromDatabaseValue(mixed $value): ?CarbonImmutable
    {
        return null !== $value && '0000-00-00 00:00:00' !== $value
            ? CarbonImmutable::createFromFormat(self::DATE_FORMAT, $value, 'UTC')
            : null;
    }

    /**
     * Extract milestone ID, treating 0 as null.
     */
    private function getMilestoneId(mixed $value): ?int
    {
        return null !== $value->milestoneid && $value->milestoneid > 0 ? (int) $value->milestoneid : null;
    }

    /**
     * Resolve a statusType string (e.g. "DONE") to all matching status int keys
     * across the granted projects the user has tickets in.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return int[]
     */
    private function resolveStatusIds(string $username, string $statusType, ?array $allowedProjects): array
    {
        $projectIds = $this->repository->getProjectIdsForUser($username, $allowedProjects);
        $statusIds = [];

        foreach ($projectIds as $projectId) {
            $labels = $this->ticketRepository->getStateLabels($projectId);
            foreach ($labels as $key => $label) {
                if (isset($label['statusType']) && $label['statusType'] === $statusType) {
                    $statusIds[] = $key;
                }
            }
        }

        return array_unique($statusIds);
    }

    /**
     * Fetch a project's id and state, or null when it does not exist.
     */
    public function findProject(int $projectId): ?object
    {
        return $this->repository->findProjectById($projectId);
    }

    /**
     * Resolve a username (email) to the zp_user id, or null when unknown.
     */
    public function findUserIdByUsername(string $username): ?int
    {
        return $this->repository->findUserIdByUsername($username);
    }

    /**
     * Whether the user may access the given project, per core's access model:
     * admins/owners always, everyone for "all" projects, client users for client
     * projects, and directly assigned users otherwise. Takes explicit ids — no
     * session dependency, safe in the API-key context.
     */
    public function isUserAssignedToProject(int $userId, int $projectId): bool
    {
        return $this->projectRepository->isUserAssignedToProject($userId, $projectId);
    }

    /**
     * Whether a milestone with the given ID exists in the given project.
     */
    public function milestoneExistsInProject(int $milestoneId, int $projectId): bool
    {
        return $this->repository->milestoneExistsInProject($milestoneId, $projectId);
    }

    /**
     * Resolve the status int a new ticket should get: the project's first NEW-typed
     * status, falling back to core's seed id (3) when the project has none configured —
     * mirrors core's own create behavior.
     */
    public function resolveNewStatusId(int $projectId): int
    {
        foreach ($this->ticketRepository->getStateLabels($projectId) as $key => $label) {
            if (isset($label['statusType']) && 'NEW' === $label['statusType']) {
                return (int) $key;
            }
        }

        return self::DEFAULT_NEW_STATUS_ID;
    }

    /**
     * Parse a dueDate input in "Y-m-d H:i:s" or "Y-m-d" form as UTC, or null when invalid.
     *
     * Bare dates get a 00:00:00 time. A round-trip format check rejects rollover dates
     * (e.g. 2026-02-30) and zero dates (0000-00-00), which createFromFormat would silently
     * normalize instead of failing.
     */
    public function parseDueDate(string $value): ?CarbonImmutable
    {
        foreach (self::DUE_DATE_INPUT_FORMATS as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value, 'UTC');
            } catch (InvalidFormatException) {
                continue;
            }

            if ($value === $date->format($format)) {
                return 'Y-m-d' === $format ? $date->startOfDay() : $date;
            }
        }

        return null;
    }

    /**
     * Create a ticket via a direct insert into zp_tickets.
     *
     * Deliberately bypasses the core Tickets service/repository: the core service calls
     * $this->authorize() which needs a logged-in session user, and the core repository
     * demands ~18 exact keys and writes '' into float columns (strict-SQL-mode hazard).
     * As a consequence NO core events and NO notifications fire — this is by design.
     *
     * $apiUser is required and the grant is re-asserted here (defense in depth): the
     * controller returns the 403, but this layer must never trust that a caller ran the
     * grant check, so a missing check fails loud rather than writing to a foreign project.
     */
    public function createTicket(ApiUser $apiUser, CreateTicketData $data): TicketData
    {
        if (! $apiUser->canAccessProject($data->projectId)) {
            throw new \RuntimeException('Databridge: createTicket called for a project not granted to the API user — grant check missing at the call site.');
        }

        $ticketId = $this->repository->insertTicket($data);

        $row = $this->repository->findTicketRowById($ticketId);

        if (null === $row) {
            throw new \RuntimeException(sprintf('Databridge: ticket row missing directly after insert (id %d).', $ticketId));
        }

        // Audit trail: no core events fire on this path, and the DB row's userId points at
        // the assignee — without this line there is no record of which API key created what.
        Log::info('Databridge: ticket created via API.', [
            'ticketId' => $ticketId,
            'projectId' => $data->projectId,
            'assigneeId' => $data->assigneeId,
            'apiUser' => $apiUser->name,
        ]);

        return $this->mapRowToTicketData($row);
    }

    /**
     * Resolve a statusType string (e.g. "DONE") to a concrete status int within one project,
     * or null when that project's scheme has no status of the type.
     *
     * Picks the first match in the scheme's sort order, which is how a user reading the
     * board would name the type: for INPROGRESS that is the earliest in-progress column,
     * not an arbitrary one.
     */
    public function resolveStatusIdForProject(int $projectId, string $statusType): ?int
    {
        foreach ($this->ticketRepository->getStateLabels($projectId) as $key => $label) {
            if (isset($label['statusType']) && $label['statusType'] === $statusType) {
                return (int) $key;
            }
        }

        return null;
    }

    /**
     * Apply a partial ticket update.
     *
     * Direct query-builder write, for the same reasons as createTicket(): core's
     * Tickets::patch() reads session('userdata.id'), which is null in an API-key request.
     * No core events and no notifications fire — by design.
     *
     * The grant is re-asserted here (defense in depth): the controller returns the 403, but
     * this layer must never trust that a caller ran the check.
     */
    public function updateTicket(ApiUser $apiUser, UpdateTicketData $data): TicketData
    {
        if (! $apiUser->canAccessProject($data->projectId)) {
            throw new \RuntimeException('Databridge: updateTicket called for a project not granted to the API user — grant check missing at the call site.');
        }

        $this->repository->updateTicket($data);

        $row = $this->repository->findTicketRowById($data->ticketId);

        if (null === $row) {
            throw new \RuntimeException(sprintf('Databridge: ticket row missing directly after update (id %d).', $data->ticketId));
        }

        // Audit trail: no core events fire on this path, so the changed field names are the
        // only record of what an API key altered.
        Log::info('Databridge: ticket updated via API.', [
            'ticketId' => $data->ticketId,
            'projectId' => $data->projectId,
            'columns' => array_keys($data->columns),
            'apiUser' => $apiUser->name,
        ]);

        return $this->mapRowToTicketData($row);
    }

    /**
     * Log time on a ticket.
     *
     * Direct query-builder write and a re-asserted grant, for the same reasons as
     * createTicket(). No core events and no notifications fire — by design.
     */
    public function createTimesheet(ApiUser $apiUser, CreateTimesheetData $data): TimesheetData
    {
        if (! $apiUser->canAccessProject($data->projectId)) {
            throw new \RuntimeException('Databridge: createTimesheet called for a project not granted to the API user — grant check missing at the call site.');
        }

        /*
         * Leantime enforces UNIQUE (userId, ticketId, workDate, kind) on timesheets. Left
         * uncaught this surfaces as a 500 carrying the failed SQL, so translate it into a
         * 409 the caller can act on.
         *
         * This makes a retry safe after a timeout: the request either booked the time or it
         * did not, and repeating it can never book the same work twice.
         */
        try {
            $timesheetId = $this->repository->insertTimesheet($data);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateEntryException(
                'Time is already logged for this person, todo, date and kind. Read the existing entries before retrying.'
            );
        }

        $row = $this->repository->findTimesheetRowById($timesheetId);

        if (null === $row) {
            throw new \RuntimeException(sprintf('Databridge: timesheet row missing directly after insert (id %d).', $timesheetId));
        }

        // Audit trail: the DB row's userId is the person the time is booked for, not the
        // caller — without this line there is no record of which API key booked it.
        Log::info('Databridge: time logged via API.', [
            'timesheetId' => $timesheetId,
            'ticketId' => $data->ticketId,
            'projectId' => $data->projectId,
            'userId' => $data->userId,
            'hours' => $data->hours,
            'apiUser' => $apiUser->name,
        ]);

        return $this->mapRowToTimesheetData($row);
    }

    /**
     * Add a comment to a ticket.
     *
     * Direct query-builder write and a re-asserted grant, for the same reasons as
     * createTicket(): core's Comments::addComment() hardcodes session('userdata.id'), which
     * is null in an API-key request. No core events and no notifications fire — by design.
     */
    public function createTicketComment(ApiUser $apiUser, CreateCommentData $data): CommentData
    {
        if (! $apiUser->canAccessProject($data->projectId)) {
            throw new \RuntimeException('Databridge: createTicketComment called for a project not granted to the API user — grant check missing at the call site.');
        }

        $commentId = $this->repository->insertTicketComment($data);

        $row = $this->repository->findCommentRowById($commentId);

        if (null === $row) {
            throw new \RuntimeException(sprintf('Databridge: comment row missing directly after insert (id %d).', $commentId));
        }

        // Audit trail: the DB row's userId is the named author, not the caller — without this
        // line there is no record of which API key posted it.
        Log::info('Databridge: comment added via API.', [
            'commentId' => $commentId,
            'ticketId' => $data->ticketId,
            'projectId' => $data->projectId,
            'userId' => $data->userId,
            'apiUser' => $apiUser->name,
        ]);

        return $this->mapRowToCommentData($row);
    }
}
