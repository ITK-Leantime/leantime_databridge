<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonInterface;

/**
 * Data model for a logged time entry returned by the Databridge API.
 */
readonly class TimesheetData
{
    /**
     * @param  int              $id          Timesheet entry ID.
     * @param  int              $ticketId    Ticket the time was booked on.
     * @param  int              $projectId   Project the ticket belongs to.
     * @param  ?int             $userId      zp_user id the time is booked for.
     * @param  ?string          $username    Username (email) of that user, null when the user is gone.
     * @param  ?CarbonInterface $workDate    The day the work was done.
     * @param  ?float           $hours       Hours booked.
     * @param  ?string          $description Free-text note.
     * @param  ?string          $kind        Booking kind (core's activity category, e.g. GENERAL_BILLABLE).
     */
    public function __construct(
        public int $id,
        public int $ticketId,
        public int $projectId,
        public ?int $userId,
        public ?string $username,
        public ?CarbonInterface $workDate,
        public ?float $hours,
        public ?string $description,
        public ?string $kind,
    ) {
    }
}
