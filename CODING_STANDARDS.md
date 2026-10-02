# Coding standards

This repo follows two files. Both are part of its standard:

1. `spec/standards/CODING_STANDARDS.md` holds the rules for every Gatepost repo.
2. `spec/standards/languages/php.md` holds the rules for PHP. Part A applies to this repo.

Rules for this repo only: call each native function with a leading backslash, such as `\strlen()`. PHP-CS-Fixer adds it. On PHP 8.4, an unqualified call inside a namespace compiles to two branches, and Xdebug counts the one that no test can reach, so the branch coverage floor of T-7 needs the backslash.
