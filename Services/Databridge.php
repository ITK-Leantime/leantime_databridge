<?php

namespace Leantime\Plugins\Databridge\Services;

use Carbon\CarbonImmutable;
use Leantime\Domain\Tickets\Repositories\Tickets as TicketRepository;
use Leantime\Plugins\Databridge\Model\TicketData;
use Leantime\Plugins\Databridge\Repositories\DatabridgeRepository;

/**
 * Service layer for the Databridge plugin.
 */
class Databridge
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabridgeRepository $repository,
        private readonly TicketRepository $ticketRepository,
    ) {}

    /**
     * Get tickets for a given username with optional date and status filtering, scoped
     * to the API user's granted projects.
     *
     * @param  ?int[]  $allowedProjects  Granted project IDs; null = no restriction.
     * @return TicketData[]
     */
    public function getTickets(string $username, int $start, int $limit, ?string $dateFrom, ?string $dateTo, ?string $status, ?array $allowedProjects): array
    {
        $statusIds = null;
        if (null !== $status) {
            $statusIds = $this->resolveStatusIds($username, strtoupper($status), $allowedProjects);
            if (empty($statusIds)) {
                return [];
            }
        }

        $values = $this->repository->getTicketsByUsername($username, $start, $limit, $dateFrom, $dateTo, $statusIds, $allowedProjects);

        return array_map(function ($value) {
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
        }, $values);
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
}
