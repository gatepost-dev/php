<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

/**
 * The most precise segment that a postcode holds, or that a GPS fix supports. The value of
 * each case is the name that every Gatepost SDK uses.
 */
enum Precision: string
{
    /** A code with only the state segment, such as EK. */
    case State = 'state';

    /** A code that stops after the LGA, such as EK-01. */
    case Lga = 'lga';

    /** A code that stops after the district, such as EK-01-A03. */
    case District = 'district';

    /** A code that stops after the area, such as EK-01-A03-FK. */
    case Area = 'area';

    /** A full code, down to the unit, such as EK-01-A03-FK-01. */
    case Unit = 'unit';
}
