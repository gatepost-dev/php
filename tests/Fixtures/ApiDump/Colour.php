<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures\ApiDump;

enum Colour: string
{
    case Red = 'red';
    case Green = 'green';

    public const DEFAULT = self::Red;

    public function label(): string
    {
        return $this->mark() . $this->value;
    }

    private function mark(): string
    {
        return '#';
    }
}
