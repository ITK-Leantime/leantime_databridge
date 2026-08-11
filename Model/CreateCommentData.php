<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Validated, fully resolved input for adding a comment to a ticket.
 *
 * The author comes from the request's "username" field, resolved to a zp_user id by the
 * controller: API-key requests have no session user to attribute the comment to.
 */
readonly class CreateCommentData
{
    /**
     * @param  int  $ticketId  Ticket to comment on (grant- and existence-checked).
     * @param  int  $projectId  The ticket's project, carried for the grant re-assert and logging.
     * @param  int  $userId  Resolved zp_user.id of the comment author.
     * @param  string  $text  Comment body.
     */
    public function __construct(
        public int $ticketId,
        public int $projectId,
        public int $userId,
        public string $text,
    ) {}
}
