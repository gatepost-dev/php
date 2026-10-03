<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Fields;
use Gatepost\Postcode\Postcode;

/**
 * The unit nearest to a point. The names and the address come at level 2 and higher, as
 * NIPOST's docs say. Gatepost has seen only responses without them.
 */
final class ReverseUnit
{
    /**
     * Each parameter is a field of the unit, and only fromFields() calls this constructor.
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private function __construct(
        /** The postcode of the unit. */
        public readonly Postcode $postcode,
        /** The distance from the point to the unit, in metres. */
        public readonly float $distanceM,
        /** How sure the gateway is that the point lies in the unit. */
        public readonly Confidence $confidence,
        /** The name of the state, or null. */
        public readonly ?string $stateName,
        /** The name of the local government area, or null. */
        public readonly ?string $lgaName,
        /** The name of the locality, or null. */
        public readonly ?string $localityName,
        /** The address of the unit, or null. */
        public readonly ?string $address,
    ) {}

    /**
     * @internal ReverseResult reads the unit from the unit field.
     *
     * @throws PostcodeException When the unit is not an object, its postcode is not the whole
     *                           postcode that the core parses, or it has no number in distance_m.
     */
    public static function fromFields(mixed $unit): self
    {
        $fields = Fields::object($unit);
        $distanceM = Fields::number($fields, 'distance_m');
        $parsed = Postcode::parse(Fields::textOrNull($fields, 'postcode') ?? '');
        $confidence = Fields::textOrNull($fields, 'confidence');

        return new self(
            $parsed->value ?? throw PostcodeException::unreadable(200),
            $distanceM,
            ($confidence === null ? null : Confidence::tryFrom($confidence)) ?? Confidence::Low,
            Fields::textOrNull($fields, 'state_name'),
            Fields::textOrNull($fields, 'lga_name'),
            Fields::textOrNull($fields, 'locality_name'),
            Fields::textOrNull($fields, 'address'),
        );
    }
}
