# Develop gatepost/postcode

Read the [contributing guide](https://github.com/gatepost-dev/.github/blob/main/CONTRIBUTING.md) before you open a pull request. The title and the body of the pull request become the squash commit on `main`. The title and the suffix ` (#N)` that GitHub adds have at most 72 characters together.

## Set up

To run every check, you need PHP 8.4 with Xdebug, Composer, Python 3.11 or later, uv, gitleaks and changie. Clone with `git clone --recurse-submodules`, then run:

```sh
composer install
composer --working-dir=tools/infection install
composer --working-dir=tools/bc-check install --ignore-platform-req=ext-intl
composer check
```

The BC check tool lists ext-intl as a requirement. Its check runs without that extension, so the install line ignores the requirement. The line also works on a machine that has ext-intl.

`composer api:check` compares the public API with the newest release tag, so run `git fetch --tags` first. It ignores one finding: the value of `Postcode::SPEC_VERSION` changes with each spec version by design, so that change is not a break of the API.

`composer run` lists the other scripts. `composer test` runs the tests without coverage. `composer mutation` runs the mutation tests, and it needs Xdebug.

## Contract scenarios

`composer check` runs no contract scenario and no example of the client, because they need the mock server, which runs on Node 22.22.2 or later in the 22 line, or on Node 24.15.0 or later. CI runs them in its `contract` job. To run them yourself, clone the js repo next to this one, start its mock server with this repo's spec, and run `composer contract`:

```sh
git clone --depth 1 https://github.com/gatepost-dev/js ../js
GATEPOST_SPEC_DIR="$PWD/spec" PORT=4010 node ../js/packages/mock-server/src/main.ts &
GATEPOST_MOCK_URL=http://127.0.0.1:4010 composer contract
kill %1
```

## Change files

Add a change file with `changie new` for each user-visible change. `composer changes:check` compares `CHANGELOG.md` with the change files.

## README examples

`tests/Unit/ReadmeTest.php` runs the offline example of the README. `tests/Contract/ReadmeExampleTest.php` runs the client example against the mock server. A test finds each example by the heading of its section, so change a heading and its test together.
