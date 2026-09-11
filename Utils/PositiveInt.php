<?php

namespace Leantime\Plugins\Databridge\Utils;

use Leantime\Plugins\Databridge\Exceptions\InvalidInputException;

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
     *
     * @return ?int
     */
    public static function parse(mixed $value): ?int
    {
        if (is_bool($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return false !== $int && $int > 0 ? $int : null;
    }

    /**
     * Parse a required positive-integer field from a request input array.
     *
     * @param  array<string, mixed> $input Request input array.
     * @param  string               $field Name of the field to read.
     * @return int
     *
     * @throws InvalidInputException When the field is missing or not a positive integer.
     */
    public static function requiredField(array $input, string $field): int
    {
        $value = self::parse($input[$field] ?? null);

        if (null === $value) {
            throw new InvalidInputException(sprintf('The "%s" field is required and must be a positive integer.', $field));
        }

        return $value;
    }

    /**
     * Parse an optional positive-integer field from a request input array; null when absent.
     *
     * @param  array<string, mixed> $input Request input array.
     * @param  string               $field Name of the field to read.
     * @return ?int
     *
     * @throws InvalidInputException When the field is present but not a positive integer.
     */
    public static function optionalField(array $input, string $field): ?int
    {
        if (! isset($input[$field])) {
            return null;
        }

        $value = self::parse($input[$field]);

        if (null === $value) {
            throw new InvalidInputException(sprintf('The "%s" field must be a positive integer.', $field));
        }

        return $value;
    }
}
