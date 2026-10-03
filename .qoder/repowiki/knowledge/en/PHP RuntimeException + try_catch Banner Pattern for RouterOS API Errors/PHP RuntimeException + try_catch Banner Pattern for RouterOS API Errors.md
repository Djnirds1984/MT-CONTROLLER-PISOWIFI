---
kind: error_handling
name: PHP RuntimeException + try/catch Banner Pattern for RouterOS API Errors
category: error_handling
scope:
    - '**'
source_files:
    - includes/RouterOS/RestClient.php
    - includes/RouterOS/LegacyApiClient.php
    - admin/hotspot.php
    - api/session.php
    - includes/helpers.php
    - includes/csrf.php
    - hotspot/errors.txt
---

## Approach

The AIRCOINS NETFI controller uses a straightforward PHP error-handling strategy built around `RuntimeException` thrown by low-level clients and caught with `try/catch (Throwable $e)` blocks at the request boundary, surfacing errors as flash banners or JSON responses. There is no custom exception hierarchy, no global error handler (`set_error_handler`), no `panic/recover`, and no middleware layer — just per-request try/catch.

## Key Files

- `includes/RouterOS/RestClient.php` — throws `RuntimeException` on cURL init failure, HTTP transport failure, and HTTP status ≥ 400; builds a human-readable message from the router's `message`/`detail`/`error` fields via `buildError()`.
- `includes/RouterOS/LegacyApiClient.php` — throws `RuntimeException` on socket connect/login failure, `!trap`/`!fatal` RouterOS binary replies, short reads, timeouts, and write failures.
- `admin/hotspot.php` — central admin page that wraps every RouterOS call in `try/catch (Throwable $e)`, converting exceptions into `aircoins_flash('error', ...)` messages displayed in a banner.
- `api/session.php` — wraps session lookup in try/catch and returns JSON via `aircoins_json()`.
- `includes/helpers.php` — provides `aircoins_json()`, which sets `Content-Type: application/json`, `X-Content-Type-Options: nosniff`, and exits after echoing either the encoded payload or `{"error":"json_encode_failed"}`.
- `includes/csrf.php` — calls `exit('CSRF validation failed')` directly on invalid tokens.
- `hotspot/errors.txt` — MikroTik hotspot error-message translations (not PHP errors); maps RouterOS hotspot error codes like `invalid-username`, `chap-missing`, `radius-timeout`, `user-session-limit` to localized strings shown to captive-portal users.

## Architecture & Conventions

1. **Low-level clients throw `RuntimeException`.** Both `RestClient::request()` and `LegacyApiClient::login()` / `exec()` / `readN()` / `writeAll()` throw `RuntimeException` with descriptive messages. The docblocks explicitly state this contract (e.g. `@throws RuntimeException On transport failure or HTTP >= 400`).

2. **Callers catch `Throwable`, not a specific type.** Admin pages use `catch (Throwable $e)` and extract `$e->getMessage()` for user-facing text. This catches both `RuntimeException` and any other throwable without needing an interface.

3. **Errors are presented as flash banners in the admin UI.** The pattern is:
   ```php
   try { $client->addHotspotUser(...); }
   catch (Throwable $e) { aircoins_flash('error', 'Add user failed: ' . $e->getMessage()); }
   ```
   The banner is then rendered via `flash--error` CSS classes.

4. **API endpoints return structured JSON.** `api/session.php` uses `aircoins_json($data, $status)` to set headers and terminate the request. Failure paths still rely on uncaught exceptions propagating out of the script (no explicit catch around the whole endpoint).

5. **CSRF failures are fatal and silent.** `csrf.php` line 65 does `exit('CSRF validation failed');` — no logging, no stack trace, no redirect.

6. **RouterOS protocol errors are translated before becoming PHP exceptions.** In `LegacyApiClient`, RouterOS `!trap` and `!fatal` tags are converted to `RuntimeException` with the original `message` attribute preserved. In `RestClient`, HTTP ≥ 400 responses are assembled into a single message string via `buildError()` that concatenates `message`, `detail`, and `error` fields when present.

7. **Captive-portal user-facing errors are externalized.** `hotspot/errors.txt` is a MikroTik hotspot configuration file defining localized messages for each hotspot error code (`internal-error`, `config-error`, `not-logged-in`, `ippool-empty`, `shutting-down`, `user-session-limit`, `license-session-limit`, `wrong-mac-username`, `chap-missing`, `invalid-username`, `invalid-mac`, `uptime-limit`, `traffic-limit`, `radius-timeout`, `auth-in-progress`, `radius-reply`). These are not handled by PHP; they are served by the router stubs.

## Conventions & Constraints

- **Convention:** All network I/O against the MikroTik router (REST or legacy binary) is wrapped in `try/catch (Throwable $e)` at the page/action level, never propagated up to a global handler.
- **Convention:** Error messages shown to admins concatenate the client-provided `$e->getMessage()` verbatim, so the underlying cause (cURL error, HTTP status, RouterOS trap) is visible in the banner.
- **Convention:** Successful JSON responses go through `aircoins_json()`, which always sets `X-Content-Type-Options: nosniff` and terminates the request with `exit`.
- **Constraint (documented):** `RestClient::request()` documents `@throws RuntimeException On transport failure or HTTP >= 400`; callers must handle it.
- **Constraint (documented):** `LegacyApiClient` methods document `@throws RuntimeException` for socket/connect/login/trap/fatal/read/write failures; the constructor itself throws on connection/auth failure.
- **Constraint (enforced by RouterOS protocol):** Binary API `!trap` and `!fatal` reply tags are treated as hard errors and converted to `RuntimeException` — there is no retry or fallback path inside the client.
- **Constraint (enforced by RouterOS protocol):** HTTP status ≥ 400 from the REST API is treated as an error and converted to `RuntimeException` via `buildError()`; successful responses return decoded arrays.
- **Constraint (enforced by CSRF helper):** Invalid CSRF tokens cause immediate process termination via `exit('CSRF validation failed')` — no response body, no redirect.
- **No global error handling:** No `set_error_handler`, no `register_shutdown_function`, no custom error-to-exception converter is present in the codebase.