<?php

namespace Leantime\Plugins\Databridge\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Leantime\Plugins\Databridge\Model\CreateCommentData;
use Leantime\Plugins\Databridge\Model\CreateTicketData;
use Leantime\Plugins\Databridge\Model\CreateTimesheetData;
use Leantime\Plugins\Databridge\Model\UpdateTicketData;

/**
 * Repository for Databridge plugin data access.
 */
class DatabridgeRepository
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Create a new query builder instance.
     *
     * @return Builder
     */
    private function query(): Builder
    {
        return app('db')->connection()->query();
    }

    /**
     * Get distinct project IDs for tickets assigned to or collaborated on by a given username,
     * restricted to the allowed projects.
     *
     * @param  string $username        Username (email) to match tickets for.
     * @param  ?int[] $allowedProjects Granted project IDs; null = no restriction.
     * @return int[]
     */
    public function getProjectIdsForUser(string $username, ?array $allowedProjects): array
    {
        return $this->buildUserTicketsQuery($username, $allowedProjects)
            ->distinct()
            ->pluck('ticket.projectId')
            ->all();
    }

    /**
     * Get tickets assigned to or collaborated on by a given username.
     *
     * @param  string  $username        Username (email) to match tickets for.
     * @param  int     $sinceId         Keyset pagination: only tickets with id >= sinceId.
     * @param  int     $limit           Maximum number of rows to return.
     * @param  ?string $dateFrom        Optional lower bound (inclusive) on dateToFinish.
     * @param  ?string $dateTo          Optional upper bound (inclusive) on dateToFinish.
     * @param  ?int[]  $statusIds       Optional list of status ints to filter on.
     * @param  ?int[]  $allowedProjects Granted project IDs; null = no restriction.
     * @return array<int, object>
     */
    public function getTicketsByUsername(string $username, int $sinceId, int $limit, ?string $dateFrom, ?string $dateTo, ?array $statusIds, ?array $allowedProjects): array
    {
        return $this->buildUserTicketsQuery($username, $allowedProjects)
            ->selectRaw('DISTINCT ticket.id, ticket.headline, ticket.projectId, ticket.status, ticket.planHours, ticket.hourRemaining, ticket.tags, ticket.dateToFinish, ticket.editTo, ticket.milestoneid, ticket.modified, editor.username')
            ->where('ticket.id', '>=', $sinceId)
            ->when(null !== $dateFrom, fn ($query) => $query->where('ticket.dateToFinish', '>=', $dateFrom))
            ->when(null !== $dateTo, fn ($query) => $query->where('ticket.dateToFinish', '<=', $dateTo))
            ->when(null !== $statusIds, fn ($query) => $query->whereIn('ticket.status', $statusIds))
            ->orderBy('ticket.id', 'ASC')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * List active users assigned to at least one of the allowed projects, each with the
     * granted project IDs they are assigned to.
     *
     * Scoped through zp_relationuserproject rather than core's isUserAssignedToProject(),
     * which also returns true for admins/owners (who reach every project) and for projects
     * with psettings 'all'. That is the right rule for "may this user open this project",
     * but the wrong one here: it would list every admin under every project and make the
     * projects array useless for picking a username to pass to the ticket endpoints.
     * Explicit assignment is what the caller actually wants to see.
     *
     * status is compared lowercased because zp_user stores both 'a' and 'A' (core does the
     * same in Users::getUserByEmail). source 'api' rows are Leantime API-key service
     * accounts, excluded here as core excludes them from its own user lists.
     *
     * @param  ?int   $projectId       Narrow to one project; null = every allowed project.
     * @param  ?int[] $allowedProjects Granted project IDs; null = no restriction.
     * @return array<int, object> Rows of user columns plus a comma-joined projectIds string.
     */
    public function getUsers(?int $projectId, ?array $allowedProjects): array
    {
        return $this->query()
            ->from('zp_user as user')
            ->join('zp_relationuserproject as rel', 'rel.userId', '=', 'user.id')
            ->selectRaw('user.id, user.username, user.firstname, user.lastname, user.jobTitle, user.department, GROUP_CONCAT(DISTINCT rel.projectId ORDER BY rel.projectId ASC) as projectIds')
            ->whereRaw('LOWER(user.status) = ?', ['a'])
            ->where(fn ($query) => $query->whereNull('user.source')->orWhere('user.source', '!=', 'api'))
            ->when(null !== $projectId, fn ($query) => $query->where('rel.projectId', '=', $projectId))
            ->when(null !== $allowedProjects, fn ($query) => $query->whereIn('rel.projectId', $allowedProjects))
            ->groupBy('user.id', 'user.username', 'user.firstname', 'user.lastname', 'user.jobTitle', 'user.department')
            ->orderBy('user.id', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * Resolve a username (email) to the zp_user id, or null when unknown.
     *
     * @return ?int
     */
    public function findUserIdByUsername(string $username): ?int
    {
        $id = $this->query()
            ->from('zp_user')
            ->where('username', '=', $username)
            ->value('id');

        return null !== $id ? (int) $id : null;
    }

    /**
     * Fetch a project's id and state, or null when it does not exist.
     *
     * Returns the row rather than the bare state because state NULL is a legal
     * value (= open) and would be indistinguishable from "no such project".
     *
     * @return ?object
     */
    public function findProjectById(int $projectId): ?object
    {
        return $this->query()
            ->from('zp_projects')
            ->select('id', 'state')
            ->where('id', '=', $projectId)
            ->first();
    }

    /**
     * Whether a milestone with the given ID exists in the given project.
     *
     * Milestones live in zp_tickets as rows with type 'milestone'.
     *
     * @return bool
     */
    public function milestoneExistsInProject(int $milestoneId, int $projectId): bool
    {
        return $this->query()
            ->from('zp_tickets')
            ->where('id', '=', $milestoneId)
            ->where('type', '=', 'milestone')
            ->where('projectId', '=', $projectId)
            ->exists();
    }

    /**
     * Insert a ticket for the given create data and return the new ticket ID.
     *
     * Owns the mapping from the validated create data to zp_tickets columns. Unset
     * optional columns are inserted as null (never ''): the float and datetime columns
     * are nullable, and '' would be rejected under strict SQL mode. Defaults mirror
     * core's create path: type 'task', date/modified now UTC, kanbanSortIndex 0.
     *
     * @return int
     */
    public function insertTicket(CreateTicketData $data): int
    {
        $now = CarbonImmutable::now('UTC')->format(self::DATE_FORMAT);

        return (int) $this->query()
            ->from('zp_tickets')
            ->insertGetId([
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
                'milestoneid' => $data->milestoneId,
                'kanbanSortIndex' => 0,
                'sortindex' => null,
                'modified' => $now,
            ]);
    }

    /**
     * Fetch a single ticket row by ID in the same column shape as getTicketsByUsername(),
     * so both can share one row-to-TicketData mapping.
     *
     * @return ?object
     */
    public function findTicketRowById(int $ticketId): ?object
    {
        return $this->query()
            ->from('zp_tickets', 'ticket')
            ->leftJoin('zp_user as editor', 'editor.id', '=', 'ticket.editorId')
            ->selectRaw('ticket.id, ticket.headline, ticket.projectId, ticket.status, ticket.planHours, ticket.hourRemaining, ticket.tags, ticket.dateToFinish, ticket.editTo, ticket.milestoneid, ticket.modified, editor.username')
            ->where('ticket.id', '=', $ticketId)
            ->first();
    }

    /**
     * List projects, restricted to the allowed projects.
     *
     * @param  ?int[] $allowedProjects Granted project IDs; null = no restriction.
     * @return array<int, object>
     */
    public function getProjects(?array $allowedProjects): array
    {
        return $this->query()
            ->from('zp_projects')
            ->select('id', 'name', 'state')
            ->when(null !== $allowedProjects, fn ($query) => $query->whereIn('id', $allowedProjects))
            ->orderBy('id', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * List milestones, optionally narrowed to one project and always restricted to the
     * allowed projects.
     *
     * @param  ?int   $projectId       Narrow to one project; null = every allowed project.
     * @param  ?int[] $allowedProjects Granted project IDs; null = no restriction.
     * @return array<int, object>
     */
    public function getMilestones(?int $projectId, ?array $allowedProjects): array
    {
        return $this->query()
            ->from('zp_tickets')
            ->select('id', 'projectId', 'headline', 'status', 'dateToFinish')
            ->where('type', '=', 'milestone')
            ->when(null !== $projectId, fn ($query) => $query->where('projectId', '=', $projectId))
            ->when(null !== $allowedProjects, fn ($query) => $query->whereIn('projectId', $allowedProjects))
            ->orderBy('id', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * List time entries for a ticket or a project, restricted to the allowed projects.
     *
     * Joins zp_tickets so entries can be project-scoped at all: zp_timesheets only knows
     * the ticket. Milestones are not excluded — core lets time be booked on them.
     *
     * @param  ?int   $ticketId        Narrow to one ticket; null = no ticket filter.
     * @param  ?int   $projectId       Narrow to one project; null = no project filter.
     * @param  ?int[] $allowedProjects Granted project IDs; null = no restriction.
     * @return array<int, object>
     */
    public function getTimesheets(?int $ticketId, ?int $projectId, ?array $allowedProjects): array
    {
        return $this->query()
            ->from('zp_timesheets', 'sheet')
            ->join('zp_tickets as ticket', 'ticket.id', '=', 'sheet.ticketId')
            ->leftJoin('zp_user as user', 'user.id', '=', 'sheet.userId')
            ->selectRaw('sheet.id, sheet.ticketId, ticket.projectId, sheet.userId, sheet.workDate, sheet.hours, sheet.description, sheet.kind, user.username')
            ->when(null !== $ticketId, fn ($query) => $query->where('sheet.ticketId', '=', $ticketId))
            ->when(null !== $projectId, fn ($query) => $query->where('ticket.projectId', '=', $projectId))
            ->when(null !== $allowedProjects, fn ($query) => $query->whereIn('ticket.projectId', $allowedProjects))
            ->orderBy('sheet.id', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * List a ticket's comments, oldest first.
     *
     * Returns every comment on the ticket, replies included: zp_comment threads via
     * commentParent, and dropping replies would silently hide part of the conversation.
     *
     * @return array<int, object>
     */
    public function getTicketComments(int $ticketId): array
    {
        return $this->query()
            ->from('zp_comment', 'comment')
            ->leftJoin('zp_user as user', 'user.id', '=', 'comment.userId')
            ->selectRaw('comment.id, comment.moduleId, comment.userId, comment.date, comment.text, user.username')
            ->where('comment.module', '=', 'ticket')
            ->where('comment.moduleId', '=', $ticketId)
            ->orderBy('comment.id', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * List metadata for the files attached to a ticket.
     *
     * Selects no content column because there is none: zp_file only holds metadata, the
     * bytes live in the storage backend under encName.
     *
     * @return array<int, object>
     */
    public function getTicketFiles(int $ticketId): array
    {
        return $this->query()
            ->from('zp_file', 'file')
            ->leftJoin('zp_user as user', 'user.id', '=', 'file.userId')
            ->selectRaw('file.id, file.moduleId, file.realName, file.extension, file.userId, file.date, user.username')
            ->where('file.module', '=', 'ticket')
            ->where('file.moduleId', '=', $ticketId)
            ->orderBy('file.id', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * Apply a partial ticket update.
     *
     * The column map comes pre-validated from UpdateTicketData, so only provided fields are
     * written and absent ones keep their value. modified is always bumped, mirroring core.
     * The affected-row count is deliberately ignored: writing the values a row already has
     * affects zero rows, so it cannot distinguish "no such ticket" — callers establish
     * existence beforehand via findTicketRowById().
     *
     * @return void
     */
    public function updateTicket(UpdateTicketData $data): void
    {
        $this->query()
            ->from('zp_tickets')
            ->where('id', '=', $data->ticketId)
            ->update($data->columns + ['modified' => CarbonImmutable::now('UTC')->format(self::DATE_FORMAT)]);
    }

    /**
     * Insert a time entry and return the new entry ID.
     *
     * description is written as null when unset rather than '': the column is nullable and
     * "no note" is not the same as an empty note. Invoicing and payment flags are left at
     * their column defaults — this API does not do billing.
     *
     * @return int
     */
    public function insertTimesheet(CreateTimesheetData $data): int
    {
        $now = CarbonImmutable::now('UTC')->format(self::DATE_FORMAT);

        return (int) $this->query()
            ->from('zp_timesheets')
            ->insertGetId([
                'userId' => $data->userId,
                'ticketId' => $data->ticketId,
                'workDate' => $data->workDate->format(self::DATE_FORMAT),
                'hours' => $data->hours,
                'description' => $data->description,
                'kind' => $data->kind,
                'modified' => $now,
            ]);
    }

    /**
     * Fetch a single time entry by ID in the same column shape as getTimesheets().
     *
     * @return ?object
     */
    public function findTimesheetRowById(int $timesheetId): ?object
    {
        return $this->query()
            ->from('zp_timesheets', 'sheet')
            ->join('zp_tickets as ticket', 'ticket.id', '=', 'sheet.ticketId')
            ->leftJoin('zp_user as user', 'user.id', '=', 'sheet.userId')
            ->selectRaw('sheet.id, sheet.ticketId, ticket.projectId, sheet.userId, sheet.workDate, sheet.hours, sheet.description, sheet.kind, user.username')
            ->where('sheet.id', '=', $timesheetId)
            ->first();
    }

    /**
     * Insert a top-level ticket comment and return the new comment ID.
     *
     * commentParent 0 marks a top-level comment; status is core's default ''. Replies are
     * not creatable through this API — nothing needs them yet.
     *
     * @return int
     */
    public function insertTicketComment(CreateCommentData $data): int
    {
        return (int) $this->query()
            ->from('zp_comment')
            ->insertGetId([
                'module' => 'ticket',
                'moduleId' => $data->ticketId,
                'userId' => $data->userId,
                'commentParent' => 0,
                'date' => CarbonImmutable::now('UTC')->format(self::DATE_FORMAT),
                'text' => $data->text,
                'status' => '',
            ]);
    }

    /**
     * Fetch a single comment by ID in the same column shape as getTicketComments().
     *
     * @return ?object
     */
    public function findCommentRowById(int $commentId): ?object
    {
        return $this->query()
            ->from('zp_comment', 'comment')
            ->leftJoin('zp_user as user', 'user.id', '=', 'comment.userId')
            ->selectRaw('comment.id, comment.moduleId, comment.userId, comment.date, comment.text, user.username')
            ->where('comment.id', '=', $commentId)
            ->first();
    }

    /**
     * Build the base query for tickets associated with a username, restricted to the
     * allowed projects. A ticket matches when the user is the assigned editor or a
     * Collaborator via zp_entity_relationship. The relationship join can multiply rows
     * for tickets with several collaborators — callers deduplicate with DISTINCT.
     *
     * @param  string $username        Username (email) to match tickets for.
     * @param  ?int[] $allowedProjects Granted project IDs; null = no restriction.
     * @return Builder
     */
    private function buildUserTicketsQuery(string $username, ?array $allowedProjects): Builder
    {
        return $this->query()
            ->from('zp_tickets', 'ticket')
            ->leftJoin('zp_user as editor', 'editor.id', '=', 'ticket.editorId')
            ->leftJoin('zp_entity_relationship as er', function ($join) {
                $join->on('er.entityA', '=', 'ticket.id')
                    ->where('er.entityAType', '=', 'Ticket')
                    ->where('er.entityBType', '=', 'User')
                    ->where('er.relationship', '=', 'Collaborator');
            })
            ->leftJoin('zp_user as collab_user', 'collab_user.id', '=', 'er.entityB')
            ->where('ticket.type', '<>', 'milestone')
            ->when(null !== $allowedProjects, fn ($query) => $query->whereIn('ticket.projectId', $allowedProjects))
            ->where(function ($q) use ($username) {
                $q->where('editor.username', '=', $username)
                    ->orWhere('collab_user.username', '=', $username);
            });
    }
}
