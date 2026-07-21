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
     * @param  ?int[]  $projects  Granted project IDs; null = all projects (only reachable
     *                            via the explicit "all" sentinel in the YAML file).
     */
    public function __construct(
        public string $name,
        public array $operations,
        public ?array $projects,
    ) {}

    /**
     * Whether this user is granted the given operation.
     */
    public function can(Operation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * Whether this user may access the given project.
     */
    public function canAccessProject(int $projectId): bool
    {
        return null === $this->projects || in_array($projectId, $this->projects, true);
    }
}
