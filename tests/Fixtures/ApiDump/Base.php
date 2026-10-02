<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures\ApiDump;

abstract class Base implements Shape
{
    abstract public function name(): string;

    final public function describe(): string
    {
        return $this->name();
    }
}
