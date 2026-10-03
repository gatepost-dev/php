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
        /** The name of the state, or null when the gateway sent none. */
        public readonly ?string $stateName,
        /** The name of the local government area, or null. */
        public readonly ?string $lgaName,
        /** The name of the locality, or null. */
        public readonly ?string $localityName,
        /** The geopolitical zone, such as NORTH CENTRAL, or null. */
        public readonly ?string $zone,
    ) {}

    /**
     * @internal LookupResult reads the address from the administrative_address field.
     *
     * @param array<array-key, mixed> $fields
     *
     * A name that is missing or is not text is null.
     */
    public static function fromFields(array $fields): self
    {
        return new self(
            Fields::textOrNull($fields, 'state_name'),
            Fields::textOrNull($fields, 'lga_name'),
            Fields::textOrNull($fields, 'locality_name'),
            Fields::textOrNull($fields, 'zone'),
        );
    }
}
