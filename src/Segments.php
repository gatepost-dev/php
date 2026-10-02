<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

use Gatepost\Postcode\Internal\SpecData;

/**
 * The five segments of a postcode. A partial postcode has null for each segment after its
 * precision.
 */
final class Segments
{
    /**
     * Two letters for the state, such as EK.
     */
    public readonly string $state;

    /**
     * Two digits for the LGA, or null in a state code.
     */
    public readonly ?string $lga;

    /**
     * Three letters or digits for the district, or null.
     */
    public readonly ?string $district;

    /**
     * Two letters for the area, or null.
     */
    public readonly ?string $area;

    /**
     * Two digits for the unit, or null.
     */
    public readonly ?string $unit;

    private function __construct(string $compact)
    {
        $state = SpecData::SEGMENTS['state'];
        $this->state = \substr($compact, $state['start'], $state['length']);
        $this->lga = self::slice($compact, 'lga');
        $this->district = self::slice($compact, 'district');
        $this->area = self::slice($compact, 'area');
        $this->unit = self::slice($compact, 'unit');
    }

    /**
     * @internal Postcode builds its segments from a checked compact code.
     */
    public static function fromCompact(string $compact): self
    {
        return new self($compact);
    }

    /**
     * @internal The segments in order, keyed by the name of their precision.
     *
     * @return array{state: string, lga: ?string, district: ?string, area: ?string, unit: ?string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'lga' => $this->lga,
            'district' => $this->district,
            'area' => $this->area,
            'unit' => $this->unit,
        ];
    }

    /**
     * @param key-of<SpecData::SEGMENTS> $name
     */
    private static function slice(string $compact, string $name): ?string
    {
        $segment = SpecData::SEGMENTS[$name];
        if (\strlen($compact) < $segment['end']) {
            return null;
        }

        return \substr($compact, $segment['start'], $segment['length']);
    }
}
