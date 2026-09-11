<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Data model for a project's progress as reported by core's project service.
 */
readonly class ProjectProgressData
{
    /**
     * @param  int    $projectId               Project ID.
     * @param  float  $percent                 Completion percentage (effort-weighted, 0–100).
     * @param  string $estimatedCompletionDate Core's estimate, or a human-readable reason why
     *                                         it cannot be given yet (e.g. too few closed
     *                                         to-dos). Plain text — core embeds an HTML button
     *                                         in those cases, which the service strips.
     * @param  string $plannedCompletionDate   Planned completion date; core currently always
     *                                         returns an empty string here.
     */
    public function __construct(
        public int $projectId,
        public float $percent,
        public string $estimatedCompletionDate,
        public string $plannedCompletionDate,
    ) {
    }
}
