<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client\Internal;

use Gatepost\Postcode\Client\PostcodeException;

/**
 * Reads the fields of a decoded response body (PHP-14). A reader takes only the fields that it
 * names, so unknown fields cost nothing (API-14). A known field with the wrong type means that
 * the client cannot read the response, which is an unexpected_response.
 *
 * @internal
 */
final class Fields
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws PostcodeException When the value is not a JSON object.
     */
    public static function object(mixed $value): array
    {
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            throw PostcodeException::unreadable(200);
        }

        return $value;
    }

    /**
     * @return ?array<array-key, mixed> The object, or null when the field is absent or null.
     *
     * @param array<array-key, mixed> $fields
     *
     * @throws PostcodeException When the field is not a JSON object.
     */
    public static function optionalObject(array $fields, string $name): ?array
    {
        $value = $fields[$name] ?? null;

        return $value === null ? null : self::object($value);
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @throws PostcodeException When the field is missing or is not true or false.
     */
    public static function bool(array $fields, string $name): bool
    {
        $value = $fields[$name] ?? null;
        if (!\is_bool($value)) {
            throw PostcodeException::unreadable(200);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @throws PostcodeException When the field is missing or is not text.
     */
    public static function string(array $fields, string $name): string
    {
        return self::optionalString($fields, $name) ?? throw PostcodeException::unreadable(200);
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @return ?string The text, or null when the field is absent or null.
     *
     * @throws PostcodeException When the field is not text.
     */
    public static function optionalString(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw PostcodeException::unreadable(200);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    public static function has(array $fields, string $name): bool
    {
        return ($fields[$name] ?? null) !== null;
    }
}
