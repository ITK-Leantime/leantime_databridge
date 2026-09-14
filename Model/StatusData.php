<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Data model for one status of a project's status scheme.
 *
 * Status ints are per-project and their labels are user-editable, so a client must never
 * hardcode either. The stable value to map on is $statusType.
 */
readonly class StatusData
{
    /**
     * @param  int     $status       The per-project status int as stored in zp_tickets.status.
     * @param  string  $label        The user-facing label configured for the project.
     * @param  ?string $statusType   Stable type: NEW, INPROGRESS or DONE. Null when a
     *                               user-defined status carries no type.
     * @param  bool    $kanbanColumn Whether the status is shown as a kanban column.
     */
    public function __construct(
        public int $status,
        public string $label,
        public ?string $statusType,
        public bool $kanbanColumn,
    ) {
    }
}
