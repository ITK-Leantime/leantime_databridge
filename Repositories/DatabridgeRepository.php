<?php

namespace Leantime\Plugins\Databridge\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

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
    public function getTicketsByUsername(string $username, int $start, int $limit, ?string $dateFrom, ?string $dateTo, ?array $statusIds, ?array $allowedProjects): array
    {
        return $this->buildUserTicketsQuery($username, $allowedProjects)
            ->selectRaw('DISTINCT ticket.id, ticket.headline, ticket.projectId, ticket.status, ticket.planHours, ticket.hourRemaining, ticket.tags, ticket.dateToFinish, ticket.editTo, ticket.milestoneid, ticket.modified, editor.username')
            ->where('ticket.id', '>=', $start)
            ->when(null !== $dateFrom, fn ($query) => $query->where('ticket.dateToFinish', '>=', $dateFrom))
            ->when(null !== $dateTo, fn ($query) => $query->where('ticket.dateToFinish', '<=', $dateTo))
            ->when(null !== $statusIds, fn ($query) => $query->whereIn('ticket.status', $statusIds))
            ->orderBy('ticket.id', 'ASC')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Build the base query for tickets associated with a username, restricted to the
     * allowed projects.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     */
    private function buildUserTicketsQuery(string $username, ?array $allowedProjects): Builder
    {
        $query = $this->query()
            ->from('zp_tickets', 'ticket')
            ->leftJoin('zp_user as editor', 'editor.id', '=', 'ticket.editorId')
            ->where('ticket.type', '<>', 'milestone')
            ->when(null !== $allowedProjects, fn ($query) => $query->whereIn('ticket.projectId', $allowedProjects));

        $entityAColumn = $this->getEntityAColumnName();

        if (null !== $entityAColumn) {
            $query->leftJoin('zp_user as collab_user', function ($join) use ($username) {
                $join->where('collab_user.username', '=', $username);
            })
                ->leftJoin('zp_entity_relationship as er', function ($join) use ($entityAColumn) {
                    $join->on('er.'.$entityAColumn, '=', 'ticket.id')
                        ->where('er.entityAType', '=', 'Ticket')
                        ->where('er.entityBType', '=', 'User')
                        ->where('er.relationship', '=', 'Collaborator')
                        ->on('er.entityB', '=', 'collab_user.id');
                })
                ->where(function ($q) use ($username) {
                    $q->where('editor.username', '=', $username)
                        ->orWhereNotNull('er.entityB');
                });
        } else {
            $query->where('editor.username', '=', $username);
        }

        return $query;
    }

    /**
     * Detect the entityA column name in zp_entity_relationship.
     *
     * Returns 'entityA' (3.7.x), 'enitityA' (3.5.12 typo), or null if neither exists.
     */
    private function getEntityAColumnName(): ?string
    {
        if (Schema::hasColumn('zp_entity_relationship', 'entityA')) {
            return 'entityA';
        }

        if (Schema::hasColumn('zp_entity_relationship', 'enitityA')) {
            return 'enitityA';
        }

        return null;
    }
}
