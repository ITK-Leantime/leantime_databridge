<?php

namespace Leantime\Plugins\Databridge\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Log;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Tickets\Repositories\Tickets as TicketRepository;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Model\CreateTicketData;
use Leantime\Plugins\Databridge\Model\TicketData;
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
     * NEW-typed status configured.
     */
    private const DEFAULT_NEW_STATUS_ID = 3;

    public function __construct(
        private readonly DatabridgeRepository $repository,
        private readonly TicketRepository $ticketRepository,
        private readonly ProjectRepository $projectRepository,
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
     * Resolve a status type (NEW/INPROGRESS/DONE) to the project's first matching status int.
     *
     * A null $statusType means "default for a new ticket": the first NEW status, falling
     * back to core's seed id (3) when the project has none configured. An explicit type
     * that matches nothing returns null so the caller can reject it.
     */
    public function resolveStatusIdForCreate(int $projectId, ?string $statusType): ?int
    {
        $wanted = $statusType ?? 'NEW';

        foreach ($this->ticketRepository->getStateLabels($projectId) as $key => $label) {
            if (isset($label['statusType']) && $wanted === $label['statusType']) {
                return (int) $key;
            }
        }

        return null === $statusType ? self::DEFAULT_NEW_STATUS_ID : null;
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

        $now = CarbonImmutable::now('UTC')->format(self::DATE_FORMAT);

        $ticketId = $this->repository->insertTicket([
            'projectId' => $data->projectId,
            'headline' => $data->name,
            'description' => $data->description ?? '',
            'type' => 'task',
            'date' => $now,
            'dateToFinish' => $data->dueDate?->format(self::DATE_FORMAT),
            'status' => $data->statusId,
            'userId' => $data->assigneeId,
            // editorId is a varchar(75) column that stores the assignee's user id as a string.
            'editorId' => (string) $data->assigneeId,
            'planHours' => $data->plannedHours,
            // A fresh ticket has all of its planned work still remaining.
            'hourRemaining' => $data->plannedHours,
            'tags' => [] !== $data->tags ? implode(',', $data->tags) : null,
            'kanbanSortIndex' => 0,
            'sortindex' => null,
            'modified' => $now,
        ]);

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
}
