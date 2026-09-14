<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Data model for a project returned by the Databridge API.
 */
readonly class ProjectData
{
    /**
     * @param  int    $id     Project ID.
     * @param  string $name   Project name.
     * @param  ?int   $state  Raw zp_projects.state; null is a legal value meaning "open".
     * @param  bool   $closed Whether the project is closed/archived (state -1). Derived from
     *                        $state so consumers do not have to know the magic number.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?int $state,
        public bool $closed,
    ) {
    }
}
