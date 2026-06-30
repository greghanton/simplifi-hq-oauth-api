# Changelog

All notable changes to `greghanton/simplifi-hq-oauth-api` are documented in this file.

## [1.1.1] - 2026-06-30

Stage 1.5 (Identity coordination) — see `OAUTH_MODERNISATION_PLAN.md` and
`STAGE1-5_MODERNISATION_PLAN.md` §17 Q5 / §19.

### Added

- `allow_service_credential_mint` config option (env `SIMPLIFI_API_ALLOW_SERVICE_MINT`, default
  `true`). When set `false` on a per-session/user-context config, a cache miss in
  `AccessToken::generateNewAccessToken()` fails closed (returns a failed `ApiResponse`, no HTTP
  call made) instead of silently minting a service-credential (`client_credentials`) token under
  that session's cache key.
- `tests/Feature/ServiceCredentialMintGuardTest.php` covering the new guard, the default-true
  passthrough behaviour, and the unaffected cached-token read path.

### Changed

- README rewritten: corrected per-consumer grant matrix (`password` grant is disabled
  server-side, not just deprecated; first-party user-scoped tokens come from the API's
  `login_token` grant, exchanged directly by the consumer, not via this package) and documented
  the user-scoped-token / fail-closed contract.
- `config.php` `VERSION` updated to `1.1.1`.

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

