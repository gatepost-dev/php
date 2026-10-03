<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Exception;
use Psr\SimpleCache\CacheException;

/**
 * The exception that a PSR-16 store throws when it refuses a call.
 */
final class StoreRefused extends Exception implements CacheException {}
