# Coding standards

This repo follows two files. Both are part of its standard:

1. `spec/standards/CODING_STANDARDS.md` holds the rules for every Gatepost repo.
2. `spec/standards/languages/php.md` holds the rules for PHP. Part A applies to this repo.

Rules for this repo only: call each native function with a leading backslash, such as `\strlen()`. PHP-CS-Fixer adds it. On PHP 8.4, an unqualified call inside a namespace compiles to two branches, and Xdebug counts the one that no test can reach, so the branch coverage floor of T-7 needs the backslash.

Another rule for this repo only: use no feature of PHP 8.2 or later. PHPStan checks PHP 8.1 to 8.5 together, so it does not stop every such feature. PHP 8.1 cannot run these features, so use none of them, even when PHPStan accepts the code:

- DNF types, such as `(A&B)|null`
- the standalone `null` type
- constants in traits
- a dynamic class constant fetch, such as `self::{$name}`
- `new` without parentheses, such as `new Foo()->bar()`
- property hooks
- `array_find()`, `array_any()` and `array_all()`
- `str_increment()`
- `ini_parse_quantity()`
- an anonymous readonly class

The CI job that runs the tests on PHP 8.1 is the real guard against these features. Do not rely on PHPStan for this rule.
