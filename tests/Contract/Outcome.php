<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Contract;

use Gatepost\Postcode\Client\AutocompleteResult;
use Gatepost\Postcode\Client\LookupResult;
use Gatepost\Postcode\Client\PostcodeException;
use Gatepost\Postcode\Client\ReverseResult;
use Gatepost\Postcode\Client\ReverseUnit;
use Gatepost\Postcode\Client\Suggestion;

/**
 * Writes a result or an error in the form of an outcome of spec/contract: the field names of
 * spec/client.md, and each postcode in its canonical form.
 */
final class Outcome
{
    /**
     * @return array<string, mixed>
     */
    public static function of(LookupResult|ReverseResult|AutocompleteResult $result): array
    {
        return match (true) {
            $result instanceof LookupResult => self::lookup($result),
            $result instanceof ReverseResult => self::reverse($result),
            default => self::autocomplete($result),
        };
    }

    /**
     * @return array{code: string, status: ?int, apiCode: ?string, retryAfterMs: ?int}
     */
    public static function error(PostcodeException $error): array
    {
        return [
            'code' => $error->errorCode()->value,
            'status' => $error->status(),
            'apiCode' => $error->apiCode(),
            'retryAfterMs' => $error->retryAfterMs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function lookup(LookupResult $result): array
    {
        $address = $result->administrativeAddress;

        return [
            'postcode' => $result->postcode->canonical,
            'valid' => $result->valid,
            'status' => $result->status,
            'levelRequested' => $result->levelRequested,
            'levelReceived' => $result->levelReceived,
            'administrativeAddress' => $address === null ? null : [
                'stateName' => $address->stateName,
                'lgaName' => $address->lgaName,
                'localityName' => $address->localityName,
                'zone' => $address->zone,
            ],
            'recentHouseAddress' => $result->recentHouseAddress,
            'buildingUseStatus' => $result->buildingUseStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reverse(ReverseResult $result): array
    {
        return [
            'found' => $result->found,
            'radiusM' => self::number($result->radiusM),
            'unit' => $result->unit === null ? null : self::unit($result->unit),
            'area' => $result->area,
            'district' => $result->district,
            'state' => $result->state,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unit(ReverseUnit $unit): array
    {
        return [
            'postcode' => $unit->postcode->canonical,
            'distanceM' => self::number($unit->distanceM),
            'confidence' => $unit->confidence->value,
            'stateName' => $unit->stateName,
            'lgaName' => $unit->lgaName,
            'localityName' => $unit->localityName,
            'address' => $unit->address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function autocomplete(AutocompleteResult $result): array
    {
        return [
            'segment' => $result->segment->value,
            'suggestions' => \array_map(static fn(Suggestion $suggestion): array => [
                'code' => $suggestion->code,
                'label' => $suggestion->label,
                'postcode' => $suggestion->postcode?->canonical,
            ], $result->suggestions),
        ];
    }

    // JSON writes 25 and 25.0 the same, and json_decode() reads 25 as an int, so a whole number
    // becomes an int before the comparison.
    private static function number(?float $metres): int|float|null
    {
        if ($metres === null) {
            return null;
        }

        return \floor($metres) === $metres ? (int) $metres : $metres;
    }
}
