<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Validated, fully resolved input for a partial ticket update.
 *
 * A PATCH must distinguish "not provided" from "provided as null", which a nullable
 * property cannot express — so this model carries the already-resolved column map
 * instead of one property per field: only keys the caller actually sent are present,
 * and absent fields are therefore untouched by the update.
 *
 * The map is built by the controller, which validates each field and resolves the
 * loose API names to zp_tickets columns (name → headline, assignee → editorId/userId,
 * status → the per-project status int, …). Constructing it directly with arbitrary
 * keys would bypass that validation.
 */
readonly class UpdateTicketData
{
    /**
     * @param  int  $ticketId  Ticket to update (grant- and existence-checked by the caller).
     * @param  int  $projectId  The ticket's project, carried for the grant re-assert and logging.
     * @param  array<string, mixed>  $columns  Non-empty map of zp_tickets column => value for
     *                                         the provided fields only.
     */
    public function __construct(
        public int $ticketId,
        public int $projectId,
        public array $columns,
    ) {}
}
