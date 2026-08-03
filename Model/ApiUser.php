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
     * @param  int  $leantimeUserId  zp_user.id of the connected ACTIVE Leantime user,
     *                               resolved from the entry's email at auth time.
     *                               Non-nullable: an ApiUser cannot exist without a
     *                               resolved identity.
     */
    public function __construct(
        public string $name,
        public array $operations,
        public ?array $projects,
        public int $leantimeUserId,
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
