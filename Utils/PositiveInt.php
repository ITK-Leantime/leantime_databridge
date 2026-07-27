<?php

namespace Leantime\Plugins\Databridge\Utils;

/**
 * Parsing of loosely-typed input values into positive integers.
 */
final class PositiveInt
{
    /**
     * Parse a value to a positive integer, or null when it is not one.
     *
     * Accepts ints and integer strings (JSON numbers, quoted YAML values) via
     * FILTER_VALIDATE_INT; rejects zero, negatives, floats, leading-zero strings,
     * and booleans (which filter_var would otherwise coerce to 0/1).
     */
    public static function parse(mixed $value): ?int
    {
        if (is_bool($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return false !== $int && $int > 0 ? $int : null;
    }
}
