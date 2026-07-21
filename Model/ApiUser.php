<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * An authenticated Databridge API user, as defined in the auth YAML file.
 *
 * Deliberately carries no API key, so instances are safe to log or serialize.
 */
readonly class ApiUser
{
    /**
     * @param  string  $name  Display name used in logs.
     * @param  Operation[]  $operations  Granted operations.
     */
    public function __construct(
        public string $name,
        public array $operations,
    ) {}

    /**
     * Whether this user is granted the given operation.
     */
    public function can(Operation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }
}
