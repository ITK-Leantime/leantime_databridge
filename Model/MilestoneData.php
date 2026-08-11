<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonInterface;

/**
 * Data model for a milestone returned by the Databridge API.
 *
 * Milestones are rows in zp_tickets with type 'milestone'.
 */
readonly class MilestoneData
{
    /**
     * @param  int  $id  Milestone ID.
     * @param  int  $projectId  Project ID.
     * @param  string  $name  Milestone headline.
     * @param  int  $status  The per-project status int.
     * @param  ?string  $statusType  Stable status type (NEW/INPROGRESS/DONE), null when the
     *                               project's scheme gives the status no type.
     * @param  ?CarbonInterface  $dueDate  Due date (dateToFinish).
     */
    public function __construct(
        public int $id,
        public int $projectId,
        public string $name,
        public int $status,
        public ?string $statusType,
        public ?CarbonInterface $dueDate,
    ) {}
}
