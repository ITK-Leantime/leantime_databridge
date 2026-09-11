<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Response wrapper for consistent API JSON structure.
 */
readonly class ResponseData
{
    /**
     * @param  array $parameters   Echo of the request parameters the response answers.
     * @param  int   $resultsCount Number of entries in $results.
     * @param  array $results      Result payload.
     */
    public function __construct(
        public array $parameters,
        public int $resultsCount,
        public array $results,
    ) {
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'parameters' => $this->parameters,
            'resultsCount' => $this->resultsCount,
            'results' => $this->results,
        ];
    }
}
