<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/gatepost-dev/.github/main/brand/gatepost-lockup-dark.svg">
    <img src="https://raw.githubusercontent.com/gatepost-dev/.github/main/brand/gatepost-lockup.svg" alt="Gatepost" width="220">
  </picture>
</p>

<p align="center"><strong>gatepost/postcode</strong></p>

<p align="center">Parse, check and format Nigeria's digital postcodes in PHP, and query NIPOST's gateway for them.</p>

<p align="center">
  <a href="https://github.com/gatepost-dev/php/actions/workflows/ci.yml"><img src="https://github.com/gatepost-dev/php/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI status"></a>
  <a href="https://packagist.org/packages/gatepost/postcode"><img src="https://img.shields.io/packagist/v/gatepost/postcode?include_prereleases&label=packagist" alt="Version on Packagist"></a>
  <a href="https://github.com/gatepost-dev/php/blob/main/LICENSE"><img src="https://img.shields.io/badge/licence-Apache--2.0-blue" alt="Licence: Apache-2.0"></a>
  <a href="https://scorecard.dev/viewer/?uri=github.com/gatepost-dev/php"><img src="https://api.scorecard.dev/projects/github.com/gatepost-dev/php/badge" alt="OpenSSF Scorecard"></a>
</p>

<p align="center">
  <a href="https://gatepost-dev.github.io/docs/">Docs</a> &middot;
  <a href="https://gatepost-dev.github.io/docs/playground/">Playground</a> &middot;
  <a href="https://gatepost-dev.github.io/docs/guides/php/">PHP guide</a> &middot;
  <a href="https://github.com/gatepost-dev/.github/blob/main/CONTRIBUTING.md">Contributing</a> &middot;
  <a href="https://github.com/gatepost-dev/php/discussions">Discussions</a>
</p>

> Unofficial. Not made or endorsed by NIPOST.

## Install

```sh
composer require gatepost/postcode:^0.1@alpha
```

The first alpha is `0.1.0-alpha.1`. Composer installs an alpha only when the constraint allows it, as this one does.

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

## Call the gateway

`PostcodeClient` needs your own NIPOST API key, a PSR-18 HTTP client and a PSR-17 request factory. This example uses Guzzle 7 (`composer require guzzlehttp/guzzle`). Keep the key on a server, because it is a secret.

```php
<?php

use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$client = new PostcodeClient(
    new Client(['timeout' => 15]),
    new HttpFactory(),
    apiKey: getenv('NIPOST_API_KEY') ?: null,
    timeoutMs: 15000,
);

try {
    $result = $client->lookup('FC-01-Z99-ZZ-01');
    echo $result->valid ? 'valid' : 'not found', "\n";
} catch (PostcodeException $error) {
    echo $error->errorCode()->value, "\n";
}
```

The example uses `FC-01-Z99-ZZ-01`, a synthetic postcode, and shows the answer of Gatepost's mock server, which CI runs it against. The real gateway does not know this postcode. PSR-18 has no timeout, so set it on your HTTP client and pass the same value as `timeoutMs`.

## Main functions

| Function | What it does |
|---|---|
| `Postcode::parse($input, $allowPartial)` | Returns a result that holds a `Postcode` or a `ParseError`. |
| `Postcode::normalize($input)` | Removes separators and makes letters upper case. It does not check the result. |
| `Postcode::isLegacy($input)` | Tells whether text is an old 6-digit postcode. |
| `$postcode->canonical`, `compact`, `display` | The three written forms of a code. |
| `$postcode->truncate()`, `parent()`, `contains()` | Shorten a code, or check whether one code holds another. |
| `$postcode->redact()` | Hides the unit of a code for logs. |
| `$client->lookup($code, $level)` | Asks the gateway what it knows about a postcode. |
| `$client->reverse($lat, $lng)` | Finds the postcode at a GPS fix. |
| `$client->autocomplete($text)` | Suggests values while a user types. |

## What it does

- Reads codes that users type or paste, including full-width letters and digits.
- Names the problem in a bad code, and suggests a fix for common typos, such as O in place of 0.
- Recognises old 6-digit postcodes.
- Shortens a code to its area, district, LGA or state.
- Retries after a 502, 503 or 504, a failed connection or a timeout, at most twice by default.
- Keeps results in a PSR-16 cache when you pass one. It keeps no result by default.
- Needs neither the intl nor the mbstring extension.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1 or later |
| HTTP, for the client | a PSR-18 client and a PSR-17 request factory |
| Gatepost spec | 0.3.0 |

## Docs

The [guide](https://gatepost-dev.github.io/docs/guides/php/) shows each function with an example. The [reference](https://gatepost-dev.github.io/docs/reference/php/) lists every public class and method.

## Support

Ask questions in [GitHub Discussions](https://github.com/gatepost-dev/php/discussions). Report bugs in [GitHub Issues](https://github.com/gatepost-dev/php/issues). Report security problems through the [private reporting form](https://github.com/gatepost-dev/php/security/advisories/new).

## Contributing

Read [`CONTRIBUTING.md`](https://github.com/gatepost-dev/.github/blob/main/CONTRIBUTING.md) before you open a pull request.

Develop: [`docs/developing.md`](docs/developing.md) shows how to set up the tools and run the checks.

## Licence

Apache-2.0. See [`LICENSE`](https://github.com/gatepost-dev/php/blob/main/LICENSE) and [`NOTICE`](https://github.com/gatepost-dev/php/blob/main/NOTICE).
