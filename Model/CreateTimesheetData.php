<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonImmutable;

/**
 * Validated, fully resolved input for logging time on a ticket.
 *
 * All fields are checked before construction: the ticket exists and sits in a granted
 * project, the user is resolved to a zp_user id, and the work date is parsed to UTC.
 */
readonly class CreateTimesheetData
{
    /**
     * @param  int  $ticketId  Ticket to book the time on (grant- and existence-checked).
     * @param  int  $projectId  The ticket's project, carried for the grant re-assert and logging.
     * @param  int  $userId  Resolved zp_user.id the time is booked for.
     * @param  CarbonImmutable  $workDate  The day the work was done, in UTC.
     * @param  float  $hours  Hours to book (> 0).
     * @param  ?string  $description  Optional note, null if unset.
     * @param  string  $kind  Booking kind; defaults to core's GENERAL_BILLABLE.
     */
    public function __construct(
        public int $ticketId,
        public int $projectId,
        public int $userId,
        public CarbonImmutable $workDate,
        public float $hours,
        public ?string $description,
        public string $kind,
    ) {}
}
