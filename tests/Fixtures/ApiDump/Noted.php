<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures\ApiDump;

final class Noted
{
    /**
     * @param ?string $note For tests. @internal in this text is no tag, so the dump shows it.
     */
    public function annotate(?string $note = null): void {}
}
