# CLAUDE.md — Simplifi HQ OAuth API (`greghanton/simplifi-hq-oauth-api`)

## What this is

A standalone **Composer package** (not a Laravel app) providing the OAuth2 client
and request dispatcher for the Joy Pilot / Simplifi HQ API tier. The GUI and other
consumers use `SimplifiApi\ApiRequest` from this package to talk to the API over
HTTP. Source lives in `src/`, config defaults in `config.php`, tests in `tests/`.

> **Naming note:** "Simplifi" / "Simplifi HQ" / "joy_pilot" and **Joy Pilot** are
> the same product (renamed). This package keeps the legacy name.

## Key facts

- **No database access.** This package is a pure HTTP/OAuth client — it has no DB
  connection, no `.env`, and no `config/database.php`. Configuration comes from
  `config.php` defaults overridden by environment variables (see `README.md` for
  the full variable table).
- Behaviour, token storage, and the OAuth grant defaults are documented in
  `README.md`; the migration direction is in `OAUTH_MODERNISATION_PLAN.md`.

## Development

```bash
composer run lint:check   # coding standards
composer run stan         # PHPStan
composer run test         # Pest / PHPUnit
```

## Local Database Access (cross-repo reference)

This package never touches the database, but the wider system does and you may need
it while debugging an end-to-end flow. The local DB is the MySQL server **Laravel
Herd bundles**, on `127.0.0.1:3306` as `root` with **no password**. No `mysql`
client is on `PATH`, but Herd ships one:

```powershell
$mysql = "C:\Users\greg\.config\herd\bin\services\mysql\8.0.45\bin\mysql.exe"
& $mysql -h 127.0.0.1 -P 3306 -u root --table <database> -e "SELECT ...;"
```

- **Six separate databases**: `accounting`, `userentity`, `external`, `internal`,
  `logging`, `settings` (e.g. `internal.migrations` = table `migrations` in schema
  `internal`).
- DB credentials and connections are owned by the **API repo**
  (`simplifi-hq-api/.env` + `config/database.php`).
- The `8.0.45` folder changes after a Herd update — re-glob
  `C:\Users\greg\.config\herd\bin\services\mysql\**\mysql.exe` if it moved.
