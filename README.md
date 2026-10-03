<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/gatepost-dev/.github/main/brand/gatepost-lockup-dark.svg">
  <img src="https://raw.githubusercontent.com/gatepost-dev/.github/main/brand/gatepost-lockup.svg" alt="gatepost" height="48">
</picture>

# gatepost/postcode

Parse, check and format Nigeria's digital postcodes in PHP, and query NIPOST's gateway for them.

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
- Queries NIPOST's gateway with your own API key: what it knows about a postcode, the postcode at a GPS fix, and suggestions while a user types.

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

## Call the gateway

`PostcodeClient` calls NIPOST's gateway with your own API key. The client needs a PSR-18 HTTP client and a PSR-17 request factory, such as Guzzle 7 (`composer require guzzlehttp/guzzle`). Keep the key on a server, because it is a secret.

The client is synchronous. Each call blocks until it has a result or throws a `PostcodeException`. It has no parallel calls and no cancel. The contract scenarios that start calls in parallel do not apply to it, and the contract suite leaves them out.

### Check a postcode with the gateway

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

The examples use `FC-01-Z99-ZZ-01`, a synthetic postcode, and show the answers of Gatepost's mock server, which CI runs them against. The real gateway does not know this postcode.

`lookup` checks the code with `Postcode::parse()` first, and sends no request for a code that fails. A postcode that the gateway does not know is a result with `valid` set to false, not an error.

A key holds a lookup level, and `lookup` sends level 1 when you give none. Pass `level: 2` to `lookup` for a higher level. A level above the grant of the key fails with `ErrorCode::Forbidden`. Levels 2 to 5 use credits. NIPOST's docs say that level 2 adds the names of the places that hold the postcode and a recent house address, and level 3 adds what the building is used for. Gatepost has seen only level 1 responses from the gateway. The fields of levels 2 to 5 are documented, not observed: they follow NIPOST's docs, and no Gatepost test has seen them from the gateway yet. `levelReceived` comes from the fields of the response, because the gateway does not say which level it sent.

### Find a place and complete a postcode

`reverse($lat, $lng)` finds the postcode at a point. An optional third argument is the radius in metres, from 0 to 250. `found` can be true while `unit` is null: the gateway then found an area, but no unit within the radius. `area`, `district` and `state` are the text that the gateway sent.

`autocomplete($text)` takes the text that a user typed. It names the segment that the user is typing, and gives the gateway's values for it. Each value comes with the partial postcode that it completes, when the text and the value make one.

```php
<?php

use Gatepost\Postcode\Client\PostcodeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$client = new PostcodeClient(
    new Client(['timeout' => 15]),
    new HttpFactory(),
    apiKey: getenv('NIPOST_API_KEY') ?: null,
    timeoutMs: 15000,
);

$place = $client->reverse(9.0, 7.0);
echo $place->unit?->postcode->canonical, "\n";

$typing = $client->autocomplete('fc 01 z');
echo $typing->segment->value, "\n";
```

Each call refuses bad input before it sends a request, and throws `ErrorCode::InvalidInput`. This covers a level outside 1 to 5, a latitude, a longitude or a radius outside its range, and `autocomplete` text that is empty, has more than 11 letters and digits, or holds another character.

### Handle errors and timeouts

Each failed call throws `PostcodeException`. `errorCode()` gives one of the cases of `ErrorCode`, such as `ErrorCode::RateLimited`. `status()` gives the HTTP status, `apiCode()` gives the gateway's own error code, and `retryAfterMs()` gives the wait that a 429 asked for. `ErrorCode::UnexpectedResponse` means that the gateway answered 200 with a body that the client cannot read. None of the messages of the client holds the API key. Show the user a message for the error code, not the message of the exception, which is for developers.

The client tries a call again after a 502, 503 or 504, a failed connection or a timeout, at most twice by default (`maxRetries`). It waits 500 ms before the first retry and twice as long before the next, plus a random part of up to 250 ms. After a 429, it waits only when `Retry-After` asks for 10 seconds or less. A timeout of `autocomplete` gets no retry, because the next keystroke replaces the call.

PSR-18 has no timeout, so the client cannot stop a slow attempt. Set the timeout on your HTTP client, and pass the same value as `timeoutMs`, as the examples do with 15 seconds. The client applies `timeoutMs` to every call, because one transport holds one timeout. 15 seconds is long enough for `autocomplete`, whose default wait is 15 seconds. The other calls wait 8 seconds by default, and `timeoutMs` replaces that value. The client reports a failed attempt that took `timeoutMs` or more as `ErrorCode::Timeout`, and a failure that came sooner as `ErrorCode::NetworkError`. If the two values differ, the client reads a slow failure with the wrong code, and tries it again when it should not.

### Keep results

The client keeps no result by default. To keep results, pass `cacheTtlMs` and a PSR-16 `cache`, such as the cache of your framework. An identical call within that time gets the kept result with no request. The client never keeps an error.

The client sets no size bound of its own, so use a store with eviction, such as Redis or APCu with a size limit. A store with no bound grows with each different postcode that you look up. The cache keys hold a keyed hash of the call, never the API key or the postcode in the clear. Two clients with the same API key and the same cache share their results.

A cache that fails never fails a call. The client reads a failed read as a miss, and skips a failed write. `clearCache()` is the exception. It removes every result that the clients of the same cache kept, also for another API key, and leaves the other entries of the cache alone. When the store fails, `clearCache()` throws the failure, so that you do not read old results after you asked to remove them.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1 or later |
| HTTP, for the client | a PSR-18 client and a PSR-17 request factory, such as Guzzle 7 |
| Gatepost spec | 0.2.0 |

## Docs

The Gatepost docs site will hold the guide and the API reference. Until it is live, each public method of `Postcode` and `PostcodeClient` has a doc comment with an example.

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

`composer check` runs no contract scenario and no example of the client, because they need the mock server, which runs on Node 22.22.2 or later. CI runs them in its `contract` job. To run them yourself, clone the js repo next to this one, start its mock server with this repo's spec, and run `composer contract`:

```sh
git clone --depth 1 https://github.com/gatepost-dev/js ../js
GATEPOST_SPEC_DIR="$PWD/spec" PORT=4010 node ../js/packages/mock-server/src/main.ts &
GATEPOST_MOCK_URL=http://127.0.0.1:4010 composer contract
kill %1
```

The BC check tool lists ext-intl as a requirement. Its check runs without that extension, so the install line ignores the requirement. The line also works on a machine that has ext-intl.

## Licence

Apache-2.0. See [`LICENSE`](https://github.com/gatepost-dev/php/blob/main/LICENSE) and [`NOTICE`](https://github.com/gatepost-dev/php/blob/main/NOTICE).
