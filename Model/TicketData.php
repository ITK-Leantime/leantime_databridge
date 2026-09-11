<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonInterface;

/**
 * Data model for a ticket returned by the Databridge API.
 */
readonly class TicketData
{
    /**
     * @param  int              $id             Ticket ID.
     * @param  int              $projectId      Project ID.
     * @param  string           $name           Ticket headline.
     * @param  ?string          $status         Status type label (e.g. NEW, INPROGRESS).
     * @param  ?int             $milestoneId    Milestone ID, null if unset.
     * @param  array            $tags           Tags as string array.
     * @param  ?string          $worker         Username (email) of the assigned editor.
     * @param  ?float           $plannedHours   Planned hours.
     * @param  ?float           $remainingHours Remaining hours.
     * @param  ?CarbonInterface $dueDate        Due date (dateToFinish).
     * @param  ?CarbonInterface $resolutionDate Resolution date (editTo).
     * @param  ?CarbonInterface $modified       Last modified datetime.
     */
    public function __construct(
        public int $id,
        public int $projectId,
        public string $name,
        public ?string $status,
        public ?int $milestoneId,
        public array $tags,
        public ?string $worker,
        public ?float $plannedHours,
        public ?float $remainingHours,
        public ?CarbonInterface $dueDate,
        public ?CarbonInterface $resolutionDate,
        public ?CarbonInterface $modified,
    ) {
    }
}
