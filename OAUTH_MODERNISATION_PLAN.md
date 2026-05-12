# Joy Pilot OAuth-API — Modernisation Programme (Stages 0–4)

**Scope:** `simplifi-hq-oauth-api` only — a small Composer library wrapping the Joy Pilot API tier with OAuth2 token fetching, caching, and a request dispatcher. Sits between the GUI tier and the API tier as the only path the GUI uses to talk to the API (317 GUI call sites).
**Stack:** PHP 8.1+, Guzzle 7, php-curl-class (until Stage 1/2 consolidation), framework-agnostic (no Laravel dependency)
**Approach:** Internal hardening across five stages. The public surface is **immutable** for the entire programme.

---

## Public surface — frozen for the entire programme

These contracts carry 317 GUI call sites. Every stage protects them. Any change here is out of scope.

- `ApiRequest::request($options, $overrideConfig = [], $timerStart = null): ApiResponse`
- `ApiRequest::requestAsync($options, $overrideConfig = []): PromiseInterface`
- `ApiRequest::batch($requests, $overrideConfig = []): array`
- `ApiRequest::batchWithConcurrency($requests, $concurrency = 5, $overrideConfig = []): array`
- `ApiRequest::addEventListener($event, $callback): void` — events `beforeRequest`, `afterRegularRequest`, `afterAsyncRequest`, `afterBatchRequest`
- `ApiResponse->success() / ->data / ->paginator / ->errors / ->throw(...) / ->nextPage() / ->allPages()`
- `ApiResponse` `Iterator` + `Countable` impl (so `foreach ($response as $row)` and `count($response)` work directly)
- URL templating syntax (`['url' => 'sales/$/invoice', 102]`)
- Option flags (`with-access-token`, `response-type`, `retry-on-authentication-exception`)

The only consumer-visible change in the entire programme is the GUI's `composer.json` pin going from `dev-master` to `^1.0`, then `^1.1`, etc.

---

## Stage 0 — Housekeeping ✓

<details>
<summary>Show stage details</summary>

**Status:** Signed off 2026-05-07. Tag `1.0.0` cut (`1.0.2` patch on top of follow-ups).

**Goal:** dependency hygiene, packaging metadata, dead-code removal, first real semver tag. No behaviour change to the public surface.

**Duration:** ~2–3 dev-days (actual: matched)

### Tasks — code

- [x] **Add Pint** — `composer require --dev laravel/pint` done; `lint` / `lint:check` scripts wired in [composer.json](composer.json); repo formatted in commit `187c6af`. Pint defaults to the Laravel preset with no config file, so no `pint.json` was added (an explicit `{"preset":"laravel"}` would be a no-op). CI Pint check deferred — see Sign-off
- [x] **Constrain `php-curl-class`** — pinned to `^13.0.0` in `composer.json`
- [x] **Add `ssl_verify` to `config.php` with default `true`** — [config.php:168](config.php) defaults `true`; both source fallbacks flipped to `?? true` at [src/ApiRequest.php:172](src/ApiRequest.php) and [src/AsyncClient.php:30](src/AsyncClient.php). Note: this is a behavioural-default change — any consumer running against self-signed or invalid certs must now set `SIMPLIFI_API_SSL_VERIFY=false` explicitly
- [x] **Delete dead code** — empty `OPTIONS` / `HEAD` switch arms removed from [src/ApiRequest.php](src/ApiRequest.php); commented-out `getCallerFromBacktrace` block removed from [src/ApiResponse.php](src/ApiResponse.php). The `__call('throw')` magic at [src/ApiResponse.php:180-191](src/ApiResponse.php) is retained as planned

### Tasks — packaging

- [x] **Fill in `composer.json` metadata** — `description`, `license: proprietary`, `authors`, `require-dev`, `scripts` all set
- [x] **Add `LICENSE`** at repo root
- [x] **Add `README.md`** — minimal install + basic-usage stub linking to forthcoming Stage 1 docs
- [x] **Add `.gitattributes`** — excludes `tests/`, `.github/`, `pint.json`, `phpunit.xml`, `OAUTH_MODERNISATION_PLAN.md`, etc. from `composer install --no-dev` archives

### Tasks — repo hygiene

- [x] **Tidy stale branches** — `add_guzzle` and `async` resolved; both gone from local and origin
- [x] **Tag `1.0.0`** — first real semver tag. `1.0.2` patch followed during ssl_verify and php-curl-class touch-ups

### Cross-repo

- [x] **GUI Stage 0 pin** — GUI's `composer.json` pins `greghanton/simplifi-hq-oauth-api: ^1.0.0` (resolves to `1.0.2` in the lockfile)

### Sign-off — what was deferred or dropped

Two items pushed to Stage 1; two items decided against:

- **Push to Stage 1 — `grant_type` default → `client_credentials`**. Flipping the default mid-flight risks silently changing auth in production if the GUI's `.env` does not set `SIMPLIFI_API_GRANT_TYPE` explicitly. Stage 1 handles this as a coordinated two-step: GUI declares its grant type explicitly in every environment first, then the OAuth-API default flips in a separate deploy
- **Push to Stage 1 — CI Pint check**. Stage 1 brings PHPStan and Pest, which together justify creating `.github/workflows/`. A Pint-only Stage 0 CI would have been a runner with one trivial check; bundling all three in Stage 1 is cleaner
- **Decided against — resolve `VERSION` constant**. The plan's "drift on day one" worry never materialised: the constant has tracked tags by hand through `1.0.0` and `1.0.2`. Removing it would cost the User-Agent version segment for no tangible benefit. Auto-deriving from `git describe` is unreliable post-Composer install. Keeping the constant; manual bump-on-tag discipline is the rule
- **Decided against — `pint.json` file**. Pint with no config file already defaults to the Laravel preset, which is what the plan called for; an explicit file would be a no-op

</details>

### Acceptance criteria — outcome

- ✅ `composer.json` has full metadata, real `php-curl-class` constraint, no wildcards
- ✅ `ssl_verify` defaults to `true` end-to-end (config + both source fallbacks)
- ✅ Dead code removed (backtrace block, empty switch arms); `__call('throw')` retained
- ✅ Stale branches resolved
- ✅ Pint installed and applied repo-wide; lint scripts wired in `composer.json`
- ✅ `1.0.0` tagged; GUI pinned to `^1.0`
- → `grant_type` default flip: handled in Stage 1 (see *OAuth grant type — coordinated default flip*)
- → CI workflow: handled in Stage 1 alongside Pest + PHPStan
- ✗ `VERSION` constant removal: decided against (see Sign-off above)

---

## Stage 1 — Hardening, envelope contract, smoke tests

<details>
<summary>Show stage details</summary>

**Goal:** Redis-default token caching, parallel-call mutex, Pest smoke tests covering **both** the legacy and new API envelopes, vanilla PHPStan blocking from install. Tag `1.1.0`.

**Duration:** ~1–1.5 weeks

**Depends on:** Stage 0 complete and `1.0.0` tagged. Coordinated with API Stage 1 — the new envelope appears in production at the end of API Stage 1, so OAuth Stage 1 must ship envelope-shape support **at the same time**.

### Token caching — Redis as default (✔️)

The package keeps the existing `temp_file` mode as a fallback; Redis becomes the documented default via the existing custom callable hook ([src/AccessToken.php:122-148](src/AccessToken.php) — `get`/`set`/`del` callables in `config.php`).

- [ ] **Document the env-var pattern** in `README.md` — `SIMPLIFI_API_ACCESS_TOKEN_STORE_AS=custom`, plus `SIMPLIFI_API_ACCESS_TOKEN_GET/SET/DEL` as JSON-encoded callables (e.g. `["\\App\\Cache\\TokenStore", "get"]`). Include a Laravel example using `Cache::store('redis')`
- [ ] **Note the `config:cache` gotcha** prominently — [src/helpers.php:19-21](src/helpers.php) falls back to Laravel's `env()`, which returns `null` outside config files in cached-config Laravel apps. Consumers wanting Redis under `config:cache` must wrap `simplifiHqOauthApiEnv()` themselves
- [ ] **Keep `temp_file` mode unchanged** as the fallback for dev/local — no consumer break

### Token refresh mutex — callable hook pattern (✔️)

A new hook pair (`lock`/`unlock`) lives in `config.php` alongside `get`/`set`/`del`. Consumers wire them — Laravel uses `Cache::lock(...)`, Symfony uses `LockFactory`, raw PHP uses Redis `SET NX EX`. **The package itself stays framework-agnostic.**

- [ ] **Add `lock` / `unlock` callable config keys** under `access_token.custom` in [config.php:122-148](config.php). Both optional — when absent, the package falls through to the current behaviour (no mutex)
- [ ] **Implement the mutex around `AccessToken::generateNewAccessToken()`** in [src/AccessToken.php:82](src/AccessToken.php):
  1. Try to acquire the lock (~10s TTL — tunable in Stage 4 against real Pulse data)
  2. If acquired: refresh the token, release the lock
  3. If not acquired: wait briefly (1–2s) for the holder, then re-read cache, return the cached token
  4. Only fall through to a fresh fetch if the cache is still empty after the wait — never have multiple parallel refreshes
- [ ] **Document the contract in README** — when consumers should wire the mutex (any production with parallel calls; especially Inertia partial reloads in Stage 2+)

### Envelope-shape support — both envelopes (✔️)

API Stage 1 introduces the new `{data, meta, links}` envelope behind an Accept-header gate. The package must read both shapes from Stage 1 onward. This is additive — old consumers see no change.

- [ ] **Update [src/ApiResponse.php:365](src/ApiResponse.php) `nextPage()`, `hasNextPage()`, `getCurrentPage()`** — feature-detect `meta`/`links` first, fall back to `paginator.{current_page,total_pages}`. Use a private `getPaginatorShape(): 'meta'|'paginator'|null` helper
- [ ] **Update [src/ApiResponse.php:98](src/ApiResponse.php) `errors()`** — feature-detect Laravel `{message, errors: {field: [msg]}}` shape vs current `{errors: [...], error: {message}}`. Both flow into the same `[['title' => ...], ...]` output
- [ ] **No change to `Iterator` impl, `count()`, `success()`** — both envelopes have a top-level `data` key, so iteration and counting already work

### Smoke tests — Pest 4.x on PHPUnit 12.x

Match the API repo's stack ([API_MODERNISATION_PLAN.md:24](API_MODERNISATION_PLAN.md) — "PHPUnit 12.x, Pest 4.x").

- [ ] **`composer require --dev pestphp/pest:^4.0 phpunit/phpunit:^12.0`** plus whichever Pest plugins the API repo uses (confirm against API's `composer.json`)
- [ ] **Test fixture set** — JSON files for both envelopes (legacy + new) covering: index page 1, index final page, single-resource show, validation-error response, auth-failure response, server-error response
- [ ] **Test coverage** —
  - `ApiRequest::request()` happy path returns `ApiResponse` with `success() === true`
  - Failed responses return `success() === false`, `errors()` populated correctly for **both** envelope shapes
  - `ApiRequest::batch()` runs requests in parallel, preserves order
  - `nextPage()` works against **both** legacy `paginator` and new `meta`/`links`
  - `Iterator` and `count()` work against both
  - Auth-retry path: intercept first call, return `AuthenticationException`, assert second call is made with a fresh token (cache cleared between)
  - Mutex path: simulate concurrent token refreshes, assert only one `oauth/token` request fires

### Static analysis — vanilla PHPStan

The package has no Laravel dependencies (verified — [composer.json](composer.json) only requires `php`, `php-curl-class`, `guzzlehttp/guzzle`, `guzzlehttp/promises`). **Larastan is the wrong tool here** — it loads Laravel-specific rules with nothing to apply to. Use `phpstan/phpstan` instead.

- [ ] **`composer require --dev phpstan/phpstan`** at level 5 (small repo, no ratchet needed)
- [ ] **`phpstan.neon`** — paths `src/`, level 5
- [ ] **Create `.github/workflows/ci.yml`** — runs `composer install`, `vendor/bin/pint --test` (non-blocking — promoted to blocking in Stage 4), `vendor/bin/phpstan analyse` (blocking), and `vendor/bin/pest` (blocking) on every push and PR. Deferred from Stage 0 because Stage 0 had no test or static-analysis gates to put in it
- [ ] **Add `vendor/bin/phpstan analyse` as a required CI check** (blocking from install — different from API/GUI which start at level 1)

### HTTP client consolidation — decide and act

The package currently runs **two** HTTP clients: `php-curl-class` (sync, [src/ApiRequest.php:5](src/ApiRequest.php)) and Guzzle (async/batch, [src/AsyncClient.php](src/AsyncClient.php)). Same job, two libraries, two SSL-verify code paths, two URL builders.

- [ ] **Grep the GUI for `->getCurl(`** — the only public-surface tie to `php-curl-class` is `ApiResponse::getCurl(): Curl` ([src/ApiResponse.php:198](src/ApiResponse.php))
- [ ] **If zero hits:** schedule consolidation onto Guzzle in **Stage 1 or Stage 2** (your call). Drops `php-curl-class` entirely, unifies `ApiResponse` and `AsyncApiResponse`, eliminates duplicated URL builders. Marked here as Stage 1; defer to Stage 2 if scope-pressed
- [ ] **If non-zero hits:** document the consumers, tag for follow-up, leave `php-curl-class` in place. Defer consolidation to Stage 4

### Documentation

- [ ] **Write `README.md` properly** — install, configuration (env vars table), basic usage examples, the `config:cache` gotcha, Redis cache setup, mutex setup, event listener examples
- [ ] **Update CHANGELOG** for `1.1.0`

### OAuth grant type — coordinated default flip

Deferred from Stage 0. The current [config.php:24](config.php) default for `grant_type` is `'password'`, which OAuth 2.1 / RFC 9700 deprecate. Flip the default to `'client_credentials'` (recommended for server-to-server) **without breaking GUI auth in production**.

The only risk is if any consumer environment is silently relying on the `password` default — flipping the default would change auth there. Mitigated by a two-step rollout that decouples "consumer declares intent" from "OAuth-API changes default".

Pre-flight:

- [ ] **Audit every GUI environment `.env`** for `SIMPLIFI_API_GRANT_TYPE`. For any environment where it is unset, set it explicitly to whatever that environment is currently using (typically `password`) and deploy the GUI. **No behaviour change yet** — the GUI is just declaring what it was doing implicitly
- [ ] **Confirm the API tier accepts `client_credentials`** — verify the Laravel Passport client used by the GUI has the `client_credentials` grant enabled. If only `password` is enabled, the API tier needs a coordinated config change too

Once every consumer environment sets `SIMPLIFI_API_GRANT_TYPE` explicitly:

- [ ] **Flip the default** at [config.php:24](config.php) from `'password'` to `'client_credentials'`. Drop the "Default to 'password' for backwards compatibility" comment
- [ ] **Update README** to document `client_credentials` as the recommended grant type and `password` as deprecated

### Tag and pin

- [ ] **Tag `1.1.0`** once smoke tests + PHPStan green in CI
- [ ] **GUI bumps pin to `^1.1`** (tracked separately in GUI repo)

</details>

### Acceptance criteria

- Redis-default token cache documented in README; `temp_file` fallback intact
- Token mutex hook pattern (`lock`/`unlock` callables) implemented; mutex is opt-in via config
- `ApiResponse` reads both legacy and new envelopes for pagination and errors; verified by Pest fixtures
- Pest smoke test suite green, covering both envelopes, auth-retry, mutex, batch
- Vanilla PHPStan level 5 blocking in CI
- `.github/workflows/ci.yml` live with Pint (non-blocking), PHPStan (blocking), Pest (blocking) checks
- `php-curl-class` either consolidated away (preferred) or formally deferred to Stage 4
- `grant_type` default flipped to `client_credentials`, with every GUI environment `.env` declaring its grant type explicitly first
- `1.1.0` tagged; GUI pinned to `^1.1`

---

## Stage 2 — Pilot watching brief

<details>
<summary>Show stage details</summary>

**Goal:** Reactive only. The Stage 1 hardening should hold under real Inertia traffic on the GUI's pilot pages (Notifications + Expenses). Reserve allowance for patches.

**Duration:** ~0–2 dev-days reactive work, distributed across the GUI's ~3–4 week pilot

**Depends on:** GUI Stage 2 underway. The GUI has registered event-listener-based metrics on OAuth-API by now (its responsibility, not ours) so token-refresh frequency is observable.

### Tasks

- [ ] **Watch token-mutex behaviour** under Inertia partial-reload load. The mechanism is the GUI registering listeners on `afterRegularRequest` / `afterAsyncRequest` / `afterBatchRequest` and emitting timing/auth-refresh-counter metrics into whatever it uses (Bugsnag breadcrumbs initially; Pulse later in Stage 4). **No package change required for this**
- [ ] **Tag a `1.1.x` patch** if any bug surfaces — mutex deadlock, lock TTL too short, envelope-shape edge case caught by smoke tests against the real API Resource shape
- [ ] **Smoke tests stay green** against any new endpoint shapes the API ships during pilot (Notifications + assets-expenses families). Update fixtures only if the new envelope shape diverges from what we asserted in Stage 1

### What is NOT happening this stage

- No public-surface change
- No structural rewrites
- No new dependencies
- No version bumps beyond patch-level

</details>

### Acceptance criteria

- No regressions reported against the OAuth-API package during the pilot
- Any patches tagged and the GUI repinned within a working day of the issue surfacing

---

## Stage 3 — Roll-out watching brief

<details>
<summary>Show stage details</summary>

**Goal:** Same reactive posture as Stage 2, scaled across the GUI's ~24 page-area migrations.

**Duration:** ~1–3 dev-days reactive work, distributed across the GUI's ~8–14 week roll-out

**Depends on:** GUI Stage 3 underway. Per-page checklist preserves `ApiRequest::request/batch` as the data-fetching path ([GUI_MODERNISATION_PLAN.md:62](GUI_MODERNISATION_PLAN.md)) — the load-bearing protection for the public surface.

### Tasks

- [ ] **Monitor token-refresh load** as Inertia partial reloads multiply parallel calls across waves. Confirm Stage 1 mutex holds. The GUI's metrics from Stage 2 stay live through Stage 3
- [ ] **Wave H specifically** — Dashboard + bank reconciliation does the densest batch fetching in the entire portal. Before Wave H goes live, verify mutex behaviour against the dashboard's parallel-batch profile specifically. If lock TTL or contention semantics need tuning, that's a `1.1.x` patch (or `1.2.0` if additive API change)
- [ ] **Smoke tests stay green** as more endpoint families migrate to API Resources. Both-envelope coverage from Stage 1 means envelope drift is caught in CI before reaching the GUI
- [ ] **Tag patches as needed** — no minor version bumps anticipated unless Wave H surfaces structural needs

### What is NOT happening this stage

- No structural changes
- No PHP version bumps (waits for Stage 4)
- No envelope-retirement work (waits for API Stage 4 Phase 5)

</details>

### Acceptance criteria

- Mutex behaviour verified clean under Wave H load before Wave H goes 100%
- No outstanding regressions tagged against OAuth-API at Stage 3 close

---

## Stage 4 — Production hardening, legacy envelope retirement

<details>
<summary>Show stage details</summary>

**Goal:** Promote Pint to blocking, react to Pulse data on mutex behaviour, retire the legacy envelope when API Stage 4 Phase 5 says so. PHP version + static-state audit if scope allows.

**Duration:** ~2–4 dev-days, mostly reactive to API/GUI Stage 4 work

**Depends on:** API Stage 4 Phase 5 retiring the legacy envelope ([API_MODERNISATION_PLAN.md:256-259](API_MODERNISATION_PLAN.md)). API plan explicitly says: *"Update OAuth package's smoke tests to drop legacy-envelope coverage."*

### Phase 1 — Observability (no install required)

- [ ] **No observability dashboard for the package itself** — too small to warrant one. Pulse on the API tier captures round-trip cost. Internal signals (cache hit/miss, mutex contention, retry frequency, lock-acquisition wait times) reach Pulse via the GUI's event-listener-based metrics established in Stage 2

### Phase 2 — Static analysis

- [ ] **Pint promoted to blocking in CI** — was non-blocking from Stage 0. Run `vendor/bin/pint` repo-wide before flipping the flag, in one formatting commit, so the first day with blocking Pint doesn't break open work
- [ ] **PHPStan stays at level 5** (already blocking from Stage 1). Consider level 6 as a follow-up after two weeks green

### Phase 3 — Mutex tuning

- [ ] **Review token-mutex behaviour at production scale** using the GUI's Pulse metrics. Compare lock-hold durations against `oauth/token` p95/p99 latency. If lock TTL needs adjustment (was set to ~10s on instinct in Stage 1), tag a `1.2.x` patch
- [ ] **Static-state audit for long-lived workers** — `AsyncClient::$client`, `ApiRequest::$config`, `ApiRequest::$events`, `ApiRequest::$defaultRequestOptions` are static. PHP-FPM is fine (process per request); Horizon (added in API Stage 4) runs long-lived workers. If Pulse shows phantom auth failures or cross-job config bleed, this is the suspect. Fix scope TBD based on what's seen — could be a request-scoped reset hook, or a Stage 5 architectural item

### Phase 4 — Legacy envelope retirement

This is the only structural OAuth-API change in Stage 4, and it's gated by API Stage 4 Phase 5.

Sequencing matters:

1. **API confirms** all consumers have migrated (30-day internal cohort log + 90-day external warning if any external)
2. **OAuth-API** trims smoke tests from "both envelopes" to "new envelope only"
3. **OAuth-API** removes the feature-detection branches in `nextPage()` / `errors()` added in Stage 1 — paths now assume `meta`/`links` and Laravel error shape
4. **Tag `1.2.0`** (additive removal of fallback paths is technically backwards-compatible since the paths only fire on old responses that no longer exist) **or `2.0.0`** if you want to signal the contract change explicitly. Recommendation: `2.0.0` for clarity, given the smoke tests change shape
5. **API removes** the Accept-header gate and deletes Transformer classes
6. **GUI bumps pin** to `^2.0` (or `^1.2`) in a coordinated PR

- [ ] **Trim Pest fixtures** to new envelope only
- [ ] **Remove feature-detection branches** in `ApiResponse::nextPage()`, `hasNextPage()`, `getCurrentPage()`, `errors()`
- [ ] **Tag** `1.2.0` or `2.0.0` per decision above
- [ ] **GUI repins** in a coordinated PR

### Phase 5 — PHP version (optional)

- [ ] **PHP `^8.1` → `^8.4`** if both API ([API_MODERNISATION_PLAN.md:4](API_MODERNISATION_PLAN.md) declares PHP 8.4) and GUI are on 8.4 by Stage 4. PHP 8.1 is EOL November 2025. Bumping forces consumers to 8.4+ but gains language features and security support. Tagged as a minor or major bump depending on whether 8.1 consumers exist in the wild

</details>

### Acceptance criteria

- Pint blocking in CI
- PHPStan level 5 still green (level 6 plan documented as follow-up)
- Mutex tuning patch applied if Pulse data warranted
- Static-state audit completed (findings documented; fixes tagged where needed)
- Legacy envelope retired in coordinated step with API/GUI; smoke tests trimmed to new envelope only
- Tagged `1.2.0` or `2.0.0` per phase 4 decision
- (Optional) PHP `^8.4` bump if conditions met

---

## Cross-stage notes

### Permanent decisions

| Decision | Made in | Rationale |
|---|---|---|
| Public surface frozen | All stages | 317 GUI call sites; consumer stability is the package's most valuable property |
| Framework-agnostic (no Laravel deps) | All stages | Other tools (jobs, scheduled commands, future internal tools) can use the package without owning Laravel |
| Vanilla PHPStan, not Larastan | Stage 1 | Package has no Laravel deps; Larastan rules have nothing to apply to |
| Callable hook pattern for cache + mutex | Stage 1 | Matches existing `get`/`set`/`del` pattern; keeps package framework-agnostic |
| Pest 4.x on PHPUnit 12.x | Stage 1 | Matches API repo stack |
| Both-envelope support from Stage 1, not Stage 4 | Stage 1 | API plan's Stage 1 explicitly requires OAuth smoke tests to cover both envelopes ([API_MODERNISATION_PLAN.md:61](API_MODERNISATION_PLAN.md)) |
| Return-don't-throw error model | All stages | Existing contract; consumers escalate via `$response->throw()` |
| Single retry on auth exception | All stages | Existing behaviour; no exponential backoff |
| No observability dashboard for the package | Stage 4 | Too small to warrant one; metrics flow via GUI event listeners into Pulse |

### Out of scope / permanently deferred

- Folding the package into the GUI — the abstraction has real value and other tools legitimately need it
- Replacing the package with native Laravel `Http::` — same reason; would also break 317 call sites
- Public-surface changes of any kind (signatures, response shape, option keys, URL templating syntax, event names)
- Pulling Laravel as a dependency
- Building a Laravel service provider for the package (consumers wire the callables themselves)
- Replacing Bugsnag-flow event listeners with native package logging

### Cross-repo coordination

| Cross-repo dependency | Direction | Stage |
|---|---|---|
| GUI Stage 0 pin to `^1.0` | OAuth blocks GUI | Stage 0 ✓ |
| OAuth `grant_type` default flip | GUI must declare grant type in every `.env` first; API tier must accept `client_credentials` | Stage 1 |
| OAuth Stage 1 envelope-shape support | API Stage 1 needs this | Stage 1 |
| OAuth Stage 1 smoke tests covering both envelopes | API Stage 1 line 61 explicitly references | Stage 1 |
| Mutex metrics into Pulse | GUI registers listeners; OAuth provides hooks (already exist) | Stage 2 onwards |
| Legacy envelope retirement | API Stage 4 Phase 5 triggers OAuth Stage 4 Phase 4 | Stage 4 |

### Programme summary

| Stage | Goal | Duration | Effort |
|---|---|---|---|
| 0 ✓ | Housekeeping, first tag (signed off 2026-05-07; tags `1.0.0` + `1.0.2`) | ~2–3 days | ~2–3 dev-days |
| 1 | Hardening, envelope contract, smoke tests | ~1–1.5 weeks | ~5–8 dev-days |
| 2 | Pilot watching brief (reactive) | distributed across GUI Stage 2 | ~0–2 dev-days |
| 3 | Roll-out watching brief (reactive) | distributed across GUI Stage 3 | ~1–3 dev-days |
| 4 | Hardening, mutex tuning, legacy envelope retirement | ~1 week | ~2–4 dev-days |
| **Total** | **Hardened, tested, properly versioned package** | **~3–4 weeks active + reactive across programme** | **~10–20 dev-days** |
