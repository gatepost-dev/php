<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Fields;

/**
 * The postcode at a point. found can be true while unit is null: the gateway then found an area,
 * but no unit within the radius.
 */
final class ReverseResult
{
    /**
     * Each parameter is a field of the result, and only fromResponse() calls this constructor.
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private function __construct(
        /** True when the gateway found a unit or an area within the radius. */
        public readonly bool $found,
        /** The radius that the gateway applied, in metres, or null when it sent none. */
        public readonly ?float $radiusM,
        /** The nearest unit within the radius, or null. */
        public readonly ?ReverseUnit $unit,
        /** The area, as the gateway sent it (the client does not parse it), or null. */
        public readonly ?string $area,
        /** The district, as the gateway sent it (the client does not parse it), or null. */
        public readonly ?string $district,
        /** The state code, such as FC, or null. */
        public readonly ?string $state,
    ) {}

    /**
     * @internal PostcodeClient reads each reverse response with this method.
     *
     * @throws PostcodeException When found is not true or false, or a unit is present and the
     *                           client cannot read it. Other parts of the wrong type give null.
     */
    public static function fromResponse(mixed $response): self
    {
        $fields = Fields::object($response);
        $unit = $fields['unit'] ?? null;

        return new self(
            Fields::bool($fields, 'found'),
            Fields::numberOrNull($fields, 'radius_m'),
            $unit === null ? null : ReverseUnit::fromFields($unit),
            Fields::textOrNull($fields, 'area'),
            Fields::textOrNull($fields, 'district'),
            Fields::textOrNull($fields, 'state'),
        );
    }
}
