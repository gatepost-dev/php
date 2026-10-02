# Support PHP 8.1 after its end of life

PHP 8.1 reached its end of life on 31 Dec 2025. VER-1 asks for an ADR when a runtime floor is past its end of life.

On 2 Oct 2026, the WordPress.org statistics (https://api.wordpress.org/stats/php/1.0/) showed 11.1 % of WordPress sites on PHP 8.1. They showed 73.6 % of sites on PHP 8.1 or later. Gatepost's planned WooCommerce plugin will bundle this package. The plugin must run on the stores that still use PHP 8.1, so the package keeps PHP 8.1 as its floor.

CI tests PHP 8.1 and the newest PHP release. The cost is a PHP 8.1 test job, and a few newer language features that the code cannot use, such as readonly classes. PHPUnit 11 and later need PHP 8.2, so the PHP 8.1 job runs PHPUnit 10.

Check these numbers again before each minor release. Raise the floor in a minor release (VER-2) when the numbers no longer justify the cost.
