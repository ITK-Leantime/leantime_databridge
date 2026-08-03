<?php

namespace Leantime\Plugins\Databridge\Exceptions;

/**
 * Thrown when a matched API key's configured email does not resolve to an active
 * Leantime user. Caught by the auth middleware (generic 401, no limiter hit); the
 * message and fields are for the log only, never returned to the consumer.
 */
class UnresolvableApiUserException extends \Exception
{
    public function __construct(
        public readonly string $userName,
        public readonly string $email,
    ) {
        parent::__construct(sprintf('API user "%s" does not resolve to an active Leantime user via "%s".', $userName, $email));
    }
}
