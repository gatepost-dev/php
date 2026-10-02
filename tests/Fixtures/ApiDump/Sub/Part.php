<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures\ApiDump\Sub;

use DateTimeImmutable;
use DateTimeInterface;
use Gatepost\Postcode\Tests\Fixtures\ApiDump\Shape;
use Gatepost\Postcode\Tests\Fixtures\ApiDump\Square;

final class Part
{
    public function shape(): Shape
    {
        return new Square();
    }

    public function moment(): DateTimeInterface
    {
        return new DateTimeImmutable('2026-10-02');
    }
}
