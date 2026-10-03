# The client depends on the PSR interfaces for HTTP and caching

The client must send HTTP requests and, when the caller turns the cache on, keep results. DEP-3 asks for an ADR for each new runtime dependency.

The package requires four packages from the PHP-FIG: `psr/http-client` (PSR-18), `psr/http-factory` (PSR-17), `psr/http-message` (PSR-7) and `psr/simple-cache` (PSR-16). Each one holds only interfaces, with no code that runs, under the MIT licence. PHP-6 names them as the only dependencies of the client. The caller brings the HTTP client and the cache, such as Guzzle or Symfony HttpClient, and the cache of the framework. The package bundles no HTTP client, so a WordPress plugin that prefixes the package with Strauss (WP-4) does not ship a second copy of Guzzle.

Guzzle is a dev dependency only. The contract tests use it as the transport.

The constraints allow PSR-7 1.1 and 2.0, and PSR-16 2.0 and 3.0, because older WordPress hosts and frameworks still pin the earlier major versions. The client calls only methods that both majors have.

PSR-18 has no timeout, and one transport holds one timeout. The caller sets the timeout on the HTTP client, and passes the same value as `timeoutMs`. The client uses that value for every call, also for autocomplete, so 15 s covers all calls. It times each attempt, and it reports a failed attempt that took `timeoutMs` or more as `timeout`. A client that needs a shorter timeout for lookups can take a second transport for autocomplete later. That change adds an option and breaks nothing.
