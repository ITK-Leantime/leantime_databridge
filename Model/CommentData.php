<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonInterface;

/**
 * Data model for a ticket comment returned by the Databridge API.
 */
readonly class CommentData
{
    /**
     * @param  int              $id       Comment ID.
     * @param  int              $ticketId The ticket the comment belongs to (zp_comment.moduleId).
     * @param  ?int             $userId   Author's zp_user id.
     * @param  ?string          $username Author's username (email), null when the user is gone.
     * @param  ?CarbonInterface $date     When the comment was posted.
     * @param  string           $text     Comment body (HTML, as stored by core's editor).
     */
    public function __construct(
        public int $id,
        public int $ticketId,
        public ?int $userId,
        public ?string $username,
        public ?CarbonInterface $date,
        public string $text,
    ) {
    }
}
