<?php

namespace Leantime\Plugins\Databridge\Exceptions;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Thrown when a resource the request named is not available to the calling API user.
 *
 * Distinct from OperationNotGrantedException, which concerns the key's operation grant
 * (read/write) and is handled in ApiKeyAuth before any endpoint runs. This one concerns a
 * single project or ticket the request asked for, and carries its own status code because
 * the two cases deliberately answer differently — see the named constructors.
 *
 * Renders itself: core's ExceptionHandler calls render() on an exception that has one, so
 * the refusal becomes the response wherever it is thrown, with no caller obliged to catch
 * it or forward a return value.
 */
final class ResourceNotAccessibleException extends \Exception
{
    /**
     * @param  ?array<string, mixed>  $warningContext  Log context when this refusal is worth
     *                                                 a warning line; null to log nothing.
     */
    private function __construct(
        string $message,
        public readonly int $statusCode,
        private readonly ?array $warningContext,
    ) {
        parent::__construct($message);
    }

    /**
     * The key's grant does not cover the project. Existence is never checked, so this
     * answer is the same for a real and a nonexistent project ID.
     */
    public static function projectNotGranted(int $projectId, string $userName): self
    {
        return new self(
            'Project not granted for this API key.',
            403,
            ['user' => $userName, 'projectId' => $projectId],
        );
    }

    /**
     * The ticket does not exist, or exists outside the grant. Both give the same 404 on
     * purpose: a distinct 403 would tell an ungranted key which ticket IDs are real.
     */
    public static function ticketNotAccessible(): self
    {
        return new self('Unknown ticket.', 404, null);
    }

    /**
     * Called by core's ExceptionHandler before render(). Anything but false tells it the
     * exception is reported, which keeps a refusal out of the log as an
     * ERROR-with-stack-trace: it is an expected answer to a request, not an application
     * fault.
     *
     * A refused project grant still gets a warning, mirroring ApiKeyAuth's refused-operation
     * line — it usually means a key is configured too narrowly. An unknown ticket is
     * ordinary client traffic and is not logged at all.
     */
    public function report(): bool
    {
        if (null !== $this->warningContext) {
            Log::warning('Databridge: project not granted', $this->warningContext);
        }

        return true;
    }

    /**
     * Called by core's ExceptionHandler. Error shape matches every other Databridge
     * error response.
     */
    public function render(): JsonResponse
    {
        return new JsonResponse(['error' => $this->getMessage()], $this->statusCode);
    }
}
