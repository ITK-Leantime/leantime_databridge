<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonImmutable;

/**
 * Validated, fully resolved input for creating a ticket.
 *
 * All fields are checked and normalized before construction: the project is grant-
 * and existence-checked, the assignee is resolved to a zp_user id, the milestone is
 * verified to belong to the target project, and the status is resolved to a concrete
 * per-project status int.
 *
 * Nullable fields are deliberate domain values, not unvalidated gaps: null means
 * "not provided" and maps to a NULL column — intentionally distinct from 0/empty
 * (e.g. plannedHours null = "no estimate", which is not an estimate of zero hours).
 */
readonly class CreateTicketData
{
    /**
     * @param  int              $projectId    Target project (grant- and existence-checked by the caller).
     * @param  int              $assigneeId   Resolved zp_user.id of the assignee (becomes editorId AND userId).
     * @param  string           $name         Ticket headline (<= 255 chars).
     * @param  ?string          $description  Optional description, null if unset.
     * @param  ?CarbonImmutable $dueDate      Due date in UTC, null if unset.
     * @param  string[]         $tags         Clean tag list (no commas, deduplicated); [] if unset.
     * @param  ?float           $plannedHours Planned hours, null if unset.
     * @param  ?int             $milestoneId  Milestone to attach the ticket to, null if unset (already
     *                                        verified to be a milestone in the target project).
     * @param  int              $statusId     Concrete per-project status int (already resolved).
     */
    public function __construct(
        public int $projectId,
        public int $assigneeId,
        public string $name,
        public ?string $description,
        public ?CarbonImmutable $dueDate,
        public array $tags,
        public ?float $plannedHours,
        public ?int $milestoneId,
        public int $statusId,
    ) {
    }
}
