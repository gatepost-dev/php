<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Fields;
use Gatepost\Postcode\Postcode;

/**
 * What the gateway knows about one postcode. A postcode that the gateway does not know is a
 * result with valid false, not an error. Levels 2 to 5 follow NIPOST's docs. Gatepost has seen
 * only level 1 responses so far.
 */
final class LookupResult
{
    // The field that each level adds, from the most to the least precise level.
    private const LEVEL_FIELDS = [
        5 => ['point_geometry'],
        4 => ['other_building_info'],
        3 => ['building_use_status'],
        2 => ['administrative_address', 'recent_house_address'],
    ];

    /**
     * Each parameter is a field of the result, and only fromResponse() calls this constructor.
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private function __construct(
        /** The postcode that the caller asked for. The client never takes it from the response. */
        public readonly Postcode $postcode,
        /** True when the gateway knows the postcode. */
        public readonly bool $valid,
        /**
         * The gateway's word for the postcode, such as valid, invalid, not_found or restricted.
         * It stays text, so a new word is not lost. It is null when the gateway sent none.
         */
        public readonly ?string $status,
        /** The lookup level that the caller asked for, from 1 to 5. */
        public readonly int $levelRequested,
        /** The lookup level of the data in the response, from 1 to 5. */
        public readonly int $levelReceived,
        /** The names of the places that hold the postcode, from level 2, or null. */
        public readonly ?AdministrativeAddress $administrativeAddress,
        /** The most recent house address of the unit, from level 2, or null. */
        public readonly ?string $recentHouseAddress,
        /** What the building is used for, such as residential, from level 3, or null. */
        public readonly ?string $buildingUseStatus,
    ) {}

    /**
     * @internal PostcodeClient reads each lookup response with this method.
     *
     * @throws PostcodeException When a known field is missing or has the wrong type.
     */
    public static function fromResponse(
        mixed $response,
        Postcode $postcode,
        int $levelRequested,
    ): self {
        $fields = Fields::object($response);
        $address = Fields::optionalObject($fields, 'administrative_address');
        $recent = Fields::optionalObject($fields, 'recent_house_address');

        return new self(
            $postcode,
            Fields::bool($fields, 'valid'),
            Fields::optionalString($fields, 'status'),
            $levelRequested,
            self::levelReceived($fields),
            $address === null ? null : AdministrativeAddress::fromFields($address),
            $recent === null ? null : Fields::optionalString($recent, 'recent'),
            Fields::optionalString($fields, 'building_use_status'),
        );
    }

    /**
     * The response does not state its level, so the client reads it from the fields.
     *
     * @param array<array-key, mixed> $fields
     */
    private static function levelReceived(array $fields): int
    {
        foreach (self::LEVEL_FIELDS as $level => $names) {
            foreach ($names as $name) {
                if (Fields::has($fields, $name)) {
                    return $level;
                }
            }
        }

        return 1;
    }
}
