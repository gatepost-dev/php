<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures\ApiDump\Internal;

final class Secret
{
    public function reveal(): string
    {
        return 'secret';
    }
}
