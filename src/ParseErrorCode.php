<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

/**
 * Why Postcode::parse() rejected its input. The value of each case is the error code that
 * every Gatepost SDK uses. The spec's grammar.md defines each code.
 */
enum ParseErrorCode: string
{
    /** No character is left after normalisation. */
    case Empty = 'empty';

    /** Exactly 6 digits are left. The input is an old NIPOST postcode. */
    case LegacyCode = 'legacy_code';

    /** A character other than A to Z and 0 to 9 is left, or the input is not valid UTF-8. */
    case BadCharacter = 'bad_character';

    /**
     * The input is over the input limit, or the code has a length that parse does not accept
     * with these options.
     */
    case BadLength = 'bad_length';

    /** The first two characters are not a state code. */
    case UnknownState = 'unknown_state';

    /** A segment breaks its rule, such as an LGA of 00 or a digit in the area. */
    case BadSegment = 'bad_segment';
}
