<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Fields;

/**
 * The names of the places that hold a postcode, from a lookup at level 2 or higher. NIPOST
 * documents this shape. Gatepost has not seen it in a response yet.
 */
final class AdministrativeAddress
{
    private function __construct(
        /** The name of the state. */
        public readonly string $stateName,
        /** The name of the local government area. */
        public readonly string $lgaName,
        /** The name of the locality. */
        public readonly string $localityName,
        /** The geopolitical zone, such as NORTH CENTRAL. */
        public readonly string $zone,
    ) {}

    /**
     * @internal LookupResult reads the address from the administrative_address field.
     *
     * @param array<array-key, mixed> $fields
     *
     * @throws PostcodeException When a field is missing or is not text.
     */
    public static function fromFields(array $fields): self
    {
        return new self(
            Fields::string($fields, 'state_name'),
            Fields::string($fields, 'lga_name'),
            Fields::string($fields, 'locality_name'),
            Fields::string($fields, 'zone'),
        );
    }
}
