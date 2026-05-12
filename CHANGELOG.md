# Changelog

All notable changes to `greghanton/simplifi-hq-oauth-api` are documented in this file.

## [1.1.0] - 2026-05-12

### Added

- Pest 4 + PHPUnit 12 smoke-test suite covering legacy and new API envelope shapes.
- PHPStan (level 5) static analysis with baseline and CI integration.
- Optional token-refresh mutex hooks via `SIMPLIFI_API_ACCESS_TOKEN_LOCK` and `SIMPLIFI_API_ACCESS_TOKEN_UNLOCK`.
- Support for both pagination envelope shapes in `ApiResponse` (`meta`/`links` and legacy `paginator`).
- Expanded package documentation in `README.md` (configuration table, usage examples, Redis setup, mutex setup, event listeners, Laravel `config:cache` guidance).

### Changed

- Consolidated sync and async requests onto Guzzle.
- Default OAuth `grant_type` is now `client_credentials` (recommended for server-to-server); `password` remains supported but deprecated.
- `config.php` package `VERSION` updated to `1.1.0`.

### Removed

- Direct `php-curl-class` dependency and related Curl-specific response helpers from active usage path.

