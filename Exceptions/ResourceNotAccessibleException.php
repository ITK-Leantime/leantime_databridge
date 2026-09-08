<?php

namespace Leantime\Plugins\Databridge\Exceptions;

/**
 * Thrown when a resource the request named is not available to the calling API user.
 *
 * Distinct from OperationNotGrantedException, which concerns the key's operation grant
 * (read/write) and is handled in ApiKeyAuth before any endpoint runs. This one concerns a
 * single project or ticket the request asked for, and carries its own status code because
 * the two cases deliberately answer differently — see the named constructors.
 *
 * The controller's guards throw it and routes.php turns it into the response, so an access
 * failure is never just another value a caller might forget to check.
 */
final class ResourceNotAccessibleException extends \Exception
{
    private function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }

    /**
     * The key's grant does not cover the project. Existence is never checked, so this
     * answer is the same for a real and a nonexistent project ID.
     */
    public static function projectNotGranted(): self
    {
        return new self('Project not granted for this API key.', 403);
    }

    /**
     * The ticket does not exist, or exists outside the grant. Both give the same 404 on
     * purpose: a distinct 403 would tell an ungranted key which ticket IDs are real.
     */
    public static function ticketNotAccessible(): self
    {
        return new self('Unknown ticket.', 404);
    }
}
