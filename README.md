<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/gatepost-dev/.github/main/brand/gatepost-lockup-dark.svg">
  <img src="https://raw.githubusercontent.com/gatepost-dev/.github/main/brand/gatepost-lockup.svg" alt="gatepost" height="48">
</picture>

# gatepost/postcode

Parse, check and format Nigeria's digital postcodes in PHP, with no network access.

> Unofficial. Not made or endorsed by NIPOST.

[![CI](https://github.com/gatepost-dev/php/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/gatepost-dev/php/actions/workflows/ci.yml)
[![Licence](https://img.shields.io/badge/licence-Apache--2.0-blue)](https://github.com/gatepost-dev/php/blob/main/LICENSE)

## Install

```sh
composer require gatepost/postcode:^0.1@alpha
```

Composer installs only stable releases unless the constraint allows an alpha.

## Quickstart

```php
<?php

use Gatepost\Postcode\Postcode;

$result = Postcode::parse('ek 01 a03 fk 01');
if ($result->isOk()) {
    echo $result->value->canonical; // EK-01-A03-FK-01
} else {
    echo $result->error->code->value; // for example bad_length
}
```

`parse` accepts any spacing, dashes and letter case. For any string, it returns a result and never throws.

## What it does

- Reads codes that users type or paste, including full-width letters and digits.
- Names the problem in a bad code, and suggests a fix for common typos, such as O in place of 0.
- Recognises old 6-digit postcodes.
- Writes a code in its compact, canonical and display forms.
- Shortens a code to its area, district, LGA or state, and checks whether one code contains another.
- Hides the unit of a code for logs.
- Runs without the intl and mbstring extensions.

### Limit the input

`parse` reads at most 64 code points of input. This is the input limit. Longer input fails with `ParseErrorCode::BadLength`, even when it holds a valid code. The check runs before `parse` normalises the input, so long text cannot stall a server. `isLegacy` applies the same limit.

```php
<?php

use Gatepost\Postcode\Postcode;

$code = 'EK-01-A03-FK-01';
echo Postcode::parse(str_pad($code, 64))->isOk() ? 'ok' : 'failed', "\n"; // ok
echo Postcode::parse(str_pad($code, 65))->error?->code->value, "\n"; // bad_length
```

`normalize` has no input limit, so call `parse` for text from an untrusted source.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1 or later |
| Gatepost spec | 0.2.0 |

## Docs

The Gatepost docs site will hold the guide and the API reference. Until it is live, each public method of `Postcode` has a doc comment with an example.

## Support

Ask questions and report bugs in [GitHub Issues](https://github.com/gatepost-dev/php/issues). Report security problems privately, as [`SECURITY.md`](https://github.com/gatepost-dev/.github/blob/main/SECURITY.md) describes.

## Contributing

Read [`CONTRIBUTING.md`](https://github.com/gatepost-dev/.github/blob/main/CONTRIBUTING.md) before you open a pull request. The title and the body of the pull request become the squash commit on `main`. The title and the suffix ` (#N)` that GitHub adds have at most 72 characters together.

To run every check, you need PHP 8.4 with Xdebug, Composer, Python 3.11 or later, uv, gitleaks and changie. Clone with `git clone --recurse-submodules`, then run:

```sh
composer install
composer --working-dir=tools/infection install
composer --working-dir=tools/bc-check install --ignore-platform-req=ext-intl
composer check
```

The BC check tool lists ext-intl as a requirement. Its check runs without that extension, so the install line ignores the requirement. The line also works on a machine that has ext-intl.

## Licence

Apache-2.0. See [`LICENSE`](https://github.com/gatepost-dev/php/blob/main/LICENSE) and [`NOTICE`](https://github.com/gatepost-dev/php/blob/main/NOTICE).
