<?php

namespace Leantime\Plugins\Databridge\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Leantime\Plugins\Databridge\Model\CreateTicketData;

/**
 * Repository for Databridge plugin data access.
 */
class DatabridgeRepository
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Create a new query builder instance.
     */
    private function query(): Builder
    {
        return app('db')->connection()->query();
    }

    /**
     * Get distinct project IDs for tickets assigned to or collaborated on by a given username,
     * restricted to the allowed projects.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
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
     * @param  ?int[]  $statusIds  Optional list of status ints to filter on.
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
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
     * Resolve a username (email) to the zp_user id, or null when unknown.
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
     * Insert a ticket for the given create data and return the new ticket ID.
     *
     * Owns the mapping from the validated create data to zp_tickets columns. Unset
     * optional columns are inserted as null (never ''): the float and datetime columns
     * are nullable, and '' would be rejected under strict SQL mode. Defaults mirror
     * core's create path: type 'task', date/modified now UTC, kanbanSortIndex 0.
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
                'kanbanSortIndex' => 0,
                'sortindex' => null,
                'modified' => $now,
            ]);
    }

    /**
     * Fetch a single ticket row by ID in the same column shape as getTicketsByUsername(),
     * so both can share one row-to-TicketData mapping.
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
     * Build the base query for tickets associated with a username, restricted to the
     * allowed projects. A ticket matches when the user is the assigned editor or a
     * Collaborator via zp_entity_relationship. The relationship join can multiply rows
     * for tickets with several collaborators — callers deduplicate with DISTINCT.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
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
