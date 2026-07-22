<?php

namespace Leantime\Plugins\Databridge\Repositories;

use Illuminate\Database\Query\Builder;

/**
 * Repository for Databridge plugin data access.
 */
class DatabridgeRepository
{
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
     * Insert a ticket row and return the new ticket ID.
     *
     * Unset optional columns must be passed as null (never ''): the zp_tickets float
     * and datetime columns are nullable, and '' would be rejected under strict SQL mode.
     *
     * @param  array<string, mixed>  $values  Column => value map.
     */
    public function insertTicket(array $values): int
    {
        return (int) $this->query()
            ->from('zp_tickets')
            ->insertGetId($values);
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
