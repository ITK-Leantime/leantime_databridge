<?php

namespace Leantime\Plugins\Databridge\Exceptions;

/**
 * Thrown when a write collides with an existing row.
 *
 * Leantime enforces UNIQUE (userId, ticketId, workDate, kind) on timesheets, so booking the
 * same work twice is rejected by the database. That protection is worth surfacing as a clear
 * 409 rather than a 500: a client whose request timed out cannot tell whether the write
 * landed, and this tells it that the entry already exists instead of leaking the SQL that
 * failed.
 *
 * The message is written for the API consumer and is safe to return verbatim.
 */
class DuplicateEntryException extends \Exception {}
