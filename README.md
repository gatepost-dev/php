# gatepost/postcode

Parse, check and format Nigeria's digital postcodes in PHP, with no network access.

> Unofficial. Not made or endorsed by NIPOST.

## Contributing

To run every check, you need PHP 8.4 with Xdebug, Composer, Python 3.11 or later, uv, gitleaks and changie. Clone with `git clone --recurse-submodules`, then run:

```sh
composer install
composer --working-dir=tools/infection install
composer --working-dir=tools/bc-check install
composer check
```

## Licence

Apache-2.0. See `LICENSE` and `NOTICE`.
