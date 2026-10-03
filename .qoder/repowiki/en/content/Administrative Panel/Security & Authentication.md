# Security & Authentication

<cite>
**Referenced Files in This Document**
- [admin/login.php](file://admin/login.php)
- [admin/logout.php](file://admin/logout.php)
- [admin/index.php](file://admin/index.php)
- [includes/auth.php](file://includes/auth.php)
- [includes/csrf.php](file://includes/csrf.php)
- [includes/crypto.php](file://includes/crypto.php)
- [includes/config.php](file://includes/config.php)
- [includes/db.php](file://includes/db.php)
- [includes/helpers.php](file://includes/helpers.php)
- [api/session.php](file://api/session.php)
</cite>

## Table of Contents
1. [Introduction](#introduction)
2. [Project Structure](#project-structure)
3. [Core Components](#core-components)
4. [Architecture Overview](#architecture-overview)
5. [Detailed Component Analysis](#detailed-component-analysis)
6. [Dependency Analysis](#dependency-analysis)
7. [Performance Considerations](#performance-considerations)
8. [Troubleshooting Guide](#troubleshooting-guide)
9. [Conclusion](#conclusion)

## Introduction
This document explains the administrative panel security and authentication system for the project. It covers secure HTTPS-only session handling, CSRF protection, rate limiting, brute-force prevention, audit logging, password hashing with Argon2id (with bcrypt fallback), logout procedures, and operational guidance to keep the admin interface secure. It also provides troubleshooting steps for common authentication and security issues.

## Project Structure
The security-relevant parts are concentrated under `admin/` for user-facing endpoints and `includes/` for shared security logic:

- Admin entry points: login, logout, dashboard.
- Shared security modules: authentication, CSRF, configuration, database schema, helpers, and encryption utilities.
- A portal-facing API endpoint that is intentionally unauthenticated but read-only and input-validated.

```mermaid
graph TB
subgraph "Admin Panel"
Login["admin/login.php"]
Logout["admin/logout.php"]
Dashboard["admin/index.php"]
end
subgraph "Shared Security Modules"
Auth["includes/auth.php"]
Csrf["includes/csrf.php"]
Config["includes/config.php"]
Db["includes/db.php"]
Helpers["includes/helpers.php"]
Crypto["includes/crypto.php"]
end
subgraph "Portal API"
SessionApi["api/session.php"]
end
Login --> Auth
Login --> Csrf
Logout --> Auth
Logout --> Csrf
Dashboard --> Auth
SessionApi --> Db
SessionApi --> Helpers
Auth --> Config
Auth --> Db
Csrf --> Config
Csrf --> Helpers
Crypto --> Config
```

**Diagram sources**
- [admin/login.php:14-18](file://admin/login.php#L14-L18)
- [admin/logout.php:15-19](file://admin/logout.php#L15-L19)
- [admin/index.php:14-17](file://admin/index.php#L14-L17)
- [includes/auth.php:14-15](file://includes/auth.php#L14-L15)
- [includes/csrf.php:12-14](file://includes/csrf.php#L12-L14)
- [includes/config.php:15-43](file://includes/config.php#L15-L43)
- [includes/db.php:12-47](file://includes/db.php#L12-L47)
- [includes/helpers.php:11-38](file://includes/helpers.php#L11-L38)
- [includes/crypto.php:15-47](file://includes/crypto.php#L15-L47)
- [api/session.php:23-31](file://api/session.php#L23-L31)

**Section sources**
- [admin/login.php:1-114](file://admin/login.php#L1-L114)
- [admin/logout.php:1-43](file://admin/logout.php#L1-L43)
- [admin/index.php:1-154](file://admin/index.php#L1-L154)
- [includes/auth.php:1-282](file://includes/auth.php#L1-L282)
- [includes/csrf.php:1-68](file://includes/csrf.php#L1-L68)
- [includes/config.php:1-44](file://includes/config.php#L1-L44)
- [includes/db.php:1-117](file://includes/db.php#L1-L117)
- [includes/helpers.php:1-97](file://includes/helpers.php#L1-L97)
- [includes/crypto.php:1-138](file://includes/crypto.php#L1-L138)
- [api/session.php:1-107](file://api/session.php#L1-L107)

## Core Components
- Authentication and session hardening: centralized in `includes/auth.php`.
- CSRF protection: token generation, rendering, and verification in `includes/csrf.php`.
- Configuration constants: timeouts, rate limits, session name, and key path in `includes/config.php`.
- Database schema and persistence: SQLite connection and tables in `includes/db.php`.
- Output escaping and JSON helpers: XSS-safe output and JSON responses in `includes/helpers.php`.
- Encryption for router credentials at rest: libsodium secretbox in `includes/crypto.php`.
- Admin UI endpoints: login, logout, and dashboard in `admin/`.
- Portal session lookup API: unauthenticated, read-only MAC-based status in `api/session.php`.

Key responsibilities:
- Enforce HTTPS-only cookies and strict SameSite policy.
- Protect state-changing requests with CSRF tokens.
- Rate-limit failed logins per client IP and clear counters on success.
- Hash passwords with Argon2id when available; fall back to bcrypt.
- Audit privileged actions and login attempts.
- Destroy sessions securely on logout.

**Section sources**
- [includes/auth.php:17-57](file://includes/auth.php#L17-L57)
- [includes/auth.php:65-83](file://includes/auth.php#L65-L83)
- [includes/auth.php:103-138](file://includes/auth.php#L103-L138)
- [includes/auth.php:151-190](file://includes/auth.php#L151-L190)
- [includes/auth.php:202-259](file://includes/auth.php#L202-L259)
- [includes/auth.php:269-281](file://includes/auth.php#L269-L281)
- [includes/csrf.php:21-67](file://includes/csrf.php#L21-L67)
- [includes/config.php:25-43](file://includes/config.php#L25-L43)
- [includes/db.php:56-116](file://includes/db.php#L56-L116)
- [includes/helpers.php:17-38](file://includes/helpers.php#L17-L38)
- [includes/crypto.php:25-47](file://includes/crypto.php#L25-L47)

## Architecture Overview
The admin panel enforces a layered security model:

- HTTP layer: TLS termination ensures HTTPS-only access; cookies are marked Secure only over HTTPS.
- Request validation: CSRF tokens protect POST endpoints.
- Authentication: Session-based admin login with idle timeout enforcement.
- Authorization: Protected pages call into an auth guard before rendering.
- Brute-force mitigation: Per-IP rate limiting using a dedicated table.
- Auditing: All login/logout and privileged actions are recorded.
- Data protection: Passwords hashed with Argon2id; router credentials encrypted at rest.

```mermaid
sequenceDiagram
participant Client as "Browser"
participant Admin as "admin/login.php"
participant Auth as "includes/auth.php"
participant Csrf as "includes/csrf.php"
participant DB as "includes/db.php"
Client->>Admin : GET /admin/login.php
Admin->>Auth : aircoins_session_start()
Admin->>DB : aircoins_schema()
Admin-->>Client : HTML form with CSRF token
Client->>Admin : POST /admin/login.php {username,password,csrf_token}
Admin->>Csrf : csrf_verify()
Admin->>Auth : aircoins_login_ok(username,password)
Auth->>Auth : aircoins_rate_limited(ip)
Auth->>DB : SELECT admins WHERE username
Auth->>Auth : aircoins_verify(password,hash)
Auth->>Auth : session_regenerate_id(true)
Auth->>DB : UPDATE pass_hash if rehash needed
Auth-->>Admin : admin row or null
Admin->>DB : aircoins_audit('login')
Admin-->>Client : 302 redirect to dashboard
```

**Diagram sources**
- [admin/login.php:37-61](file://admin/login.php#L37-L61)
- [includes/csrf.php:48-67](file://includes/csrf.php#L48-L67)
- [includes/auth.php:151-190](file://includes/auth.php#L151-L190)
- [includes/db.php:56-116](file://includes/db.php#L56-L116)

## Detailed Component Analysis

### Secure HTTPS-Only Admin Interface and Session Management
- The admin session cookie is configured with:
  - Path set to `/` so it is shared across admin and API paths.
  - HttpOnly enabled to prevent JavaScript access.
  - SameSite=Strict to mitigate cross-site request forgery.
  - Secure flag set only when the request arrives over HTTPS.
- Idle timeout: sessions expire after a configurable period of inactivity, forcing re-authentication.
- Session regeneration occurs on successful login to prevent fixation attacks.

```mermaid
flowchart TD
Start(["Request enters admin"]) --> CheckTLS["Detect HTTPS"]
CheckTLS --> SetCookie["Set session cookie<br/>Secure=HTTPS? SameSite=Strict HttpOnly"]
SetCookie --> RequireLogin{"Authenticated?"}
RequireLogin --> |No| Redirect["Redirect to login"]
RequireLogin --> |Yes| CheckIdle{"Within idle timeout?"}
CheckIdle --> |No| DestroySession["Destroy session and redirect"]
CheckIdle --> |Yes| Proceed["Render protected page"]
```

**Diagram sources**
- [includes/auth.php:65-83](file://includes/auth.php#L65-L83)
- [includes/auth.php:202-231](file://includes/auth.php#L202-L231)

**Section sources**
- [includes/auth.php:65-83](file://includes/auth.php#L65-L83)
- [includes/auth.php:202-231](file://includes/auth.php#L202-L231)
- [includes/config.php:25-33](file://includes/config.php#L25-L33)

### CSRF Protection Implementation
- Each session holds a single random CSRF token generated from cryptographically secure random bytes.
- Forms include a hidden field containing the token.
- State-changing POST endpoints verify the token using a timing-safe comparison.
- Non-POST requests bypass verification, allowing safe inclusion of CSRF checks at the top of endpoints.

```mermaid
sequenceDiagram
participant Browser as "Browser"
participant Form as "Form Page"
participant Endpoint as "State-Changing Endpoint"
participant Csrf as "CSRF Module"
Browser->>Form : GET page
Form->>Csrf : csrf_field()
Csrf-->>Form : Hidden <input name="csrf_token">
Browser->>Endpoint : POST {csrf_token}
Endpoint->>Csrf : csrf_verify()
Csrf-->>Endpoint : OK or 403
```

**Diagram sources**
- [includes/csrf.php:21-40](file://includes/csrf.php#L21-L40)
- [includes/csrf.php:48-67](file://includes/csrf.php#L48-L67)

**Section sources**
- [includes/csrf.php:1-68](file://includes/csrf.php#L1-L68)
- [admin/login.php:37-39](file://admin/login.php#L37-L39)
- [admin/logout.php:21-27](file://admin/logout.php#L21-L27)

### Rate Limiting and Brute Force Prevention
- Failed login attempts are tracked per client IP in a dedicated table.
- Before authenticating, the system checks whether the IP has exceeded the maximum number of failures within a time window.
- On successful login, the failure counter for the IP is cleared.
- Default settings allow a limited number of attempts within a short window to reduce brute-force risk.

```mermaid
flowchart TD
Attempt["Failed login attempt"] --> Record["Record attempt with timestamp"]
Record --> Prune["Prune old attempts older than one hour"]
Attempt --> CheckLimit{"Attempts >= threshold in window?"}
CheckLimit --> |Yes| Block["Block login until window expires"]
CheckLimit --> |No| Allow["Allow next attempt"]
Success["Successful login"] --> Clear["Clear failed attempts for IP"]
```

**Diagram sources**
- [includes/auth.php:103-138](file://includes/auth.php#L103-L138)
- [includes/auth.php:151-190](file://includes/auth.php#L151-L190)
- [includes/db.php:84-116](file://includes/db.php#L84-L116)

**Section sources**
- [includes/auth.php:96-138](file://includes/auth.php#L96-L138)
- [includes/auth.php:151-190](file://includes/auth.php#L151-L190)
- [includes/config.php:35-43](file://includes/config.php#L35-L43)

### Audit Logging System
- Every login and logout action is audited with the acting admin ID, action type, optional detail, client IP, and timestamp.
- Audit entries are written to a dedicated table.
- Logout auditing is best-effort; failures do not block sign-out.

```mermaid
sequenceDiagram
participant AdminUI as "Admin UI"
participant Auth as "auth.php"
participant DB as "db.php"
AdminUI->>Auth : aircoins_audit(admin_id, 'login', 'admin login')
Auth->>DB : INSERT INTO audit_log
AdminUI->>Auth : aircoins_audit(admin_id, 'logout', 'admin logout')
Auth->>DB : INSERT INTO audit_log
```

**Diagram sources**
- [includes/auth.php:269-281](file://includes/auth.php#L269-L281)
- [admin/login.php:46-50](file://admin/login.php#L46-L50)
- [admin/logout.php:29-37](file://admin/logout.php#L29-L37)

**Section sources**
- [includes/auth.php:269-281](file://includes/auth.php#L269-L281)
- [admin/login.php:46-50](file://admin/login.php#L46-L50)
- [admin/logout.php:29-37](file://admin/logout.php#L29-L37)

### Password Hashing with Argon2id and Secure Verification
- Password hashing prefers Argon2id when available and falls back to bcrypt otherwise.
- Verification uses constant-time comparison via the standard library.
- After successful verification, hashes are automatically migrated to the stronger algorithm when needed.

```mermaid
flowchart TD
Store["Store new password"] --> Hash["Hash with Argon2id or bcrypt"]
Verify["Verify submitted password"] --> Compare["Constant-time compare"]
Compare --> Migrate{"Needs rehash?"}
Migrate --> |Yes| Update["Update stored hash to current algorithm"]
Migrate --> |No| Keep["Keep existing hash"]
```

**Diagram sources**
- [includes/auth.php:17-57](file://includes/auth.php#L17-L57)
- [includes/auth.php:168-174](file://includes/auth.php#L168-L174)

**Section sources**
- [includes/auth.php:17-57](file://includes/auth.php#L17-L57)
- [includes/auth.php:168-174](file://includes/auth.php#L168-L174)

### Secure Logout Procedures
- Logout is restricted to POST requests carrying a valid CSRF token.
- Non-POST requests are redirected without modifying the session, preventing logout-CSRF.
- On successful logout, the session is destroyed, its cookie is cleared, and the operator is redirected to the login page.
- Logout auditing is performed when possible and does not block sign-out on errors.

```mermaid
sequenceDiagram
participant Browser as "Browser"
participant Logout as "admin/logout.php"
participant Csrf as "includes/csrf.php"
participant Auth as "includes/auth.php"
Browser->>Logout : POST /admin/logout.php {csrf_token}
Logout->>Csrf : csrf_verify()
Logout->>Auth : aircoins_audit('logout')
Logout->>Auth : aircoins_session_destroy()
Logout-->>Browser : 302 redirect to login
```

**Diagram sources**
- [admin/logout.php:21-42](file://admin/logout.php#L21-L42)
- [includes/csrf.php:48-67](file://includes/csrf.php#L48-L67)
- [includes/auth.php:236-246](file://includes/auth.php#L236-L246)

**Section sources**
- [admin/logout.php:1-43](file://admin/logout.php#L1-L43)
- [includes/auth.php:236-246](file://includes/auth.php#L236-L246)

### Administrative Interface Access Controls
- Protected pages call into an authentication guard that:
  - Starts the hardened session.
  - Checks for an active admin session.
  - Enforces idle timeout and redirects to login if expired.
  - Reloads the admin record from the database to ensure validity.
- The dashboard requires authentication before rendering any content.

```mermaid
flowchart TD
Enter["Enter protected page"] --> Guard["aircoins_require_login()"]
Guard --> HasSession{"Has user_id?"}
HasSession --> |No| ToLogin["Redirect to login"]
HasSession --> |Yes| IdleCheck{"Within idle timeout?"}
IdleCheck --> |No| Destroy["Destroy session and redirect"]
IdleCheck --> |Yes| LoadAdmin["Load admin by id"]
LoadAdmin --> Valid{"Admin exists?"}
Valid --> |No| Destroy
Valid --> |Yes| Render["Render page"]
```

**Diagram sources**
- [includes/auth.php:202-231](file://includes/auth.php#L202-L231)
- [admin/index.php:17-17](file://admin/index.php#L17-L17)

**Section sources**
- [includes/auth.php:202-231](file://includes/auth.php#L202-L231)
- [admin/index.php:17-17](file://admin/index.php#L17-L17)

### Encryption for Router Credentials
- Router passwords are stored encrypted at rest using libsodium’s XSalsa20-Poly1305 secret box.
- The 32-byte key is loaded from a file outside the web root and cached during the request.
- Plaintext values are never persisted; decryption occurs only in memory for the duration of a request.

```mermaid
flowchart TD
KeyFile["AIRCOINS_KEY file"] --> LoadKey["aircoins_key()"]
LoadKey --> Normalize["Normalize key format"]
Normalize --> Encrypt["Encrypt plaintext password"]
Encrypt --> Store["Store base64(nonce||ciphertext)"]
Decrypt["Decrypt payload"] --> Validate["Validate structure and integrity"]
Validate --> ReturnPlain["Return plaintext in memory"]
```

**Diagram sources**
- [includes/crypto.php:25-47](file://includes/crypto.php#L25-L47)
- [includes/crypto.php:91-102](file://includes/crypto.php#L91-L102)
- [includes/crypto.php:111-137](file://includes/crypto.php#L111-L137)

**Section sources**
- [includes/crypto.php:1-138](file://includes/crypto.php#L1-L138)
- [includes/config.php:20-23](file://includes/config.php#L20-L23)

### Portal-Facing Session Lookup API
- This endpoint is intentionally unauthenticated and read-only.
- It accepts a normalized MAC address and returns live hotspot session data if found.
- CORS is opened for this endpoint only; stack traces are suppressed.
- Input validation ensures malformed MAC addresses return a controlled error response.

```mermaid
sequenceDiagram
participant Portal as "Portal JS"
participant Api as "api/session.php"
participant DB as "includes/db.php"
participant Router as "RouterOS Client"
Portal->>Api : GET ?mac=AA : BB : CC : DD : EE : FF
Api->>Api : Normalize and validate MAC
Api->>DB : Query enabled routers
loop For each router
Api->>Router : findActiveByMac(mac)
Router-->>Api : Session data or error
end
Api-->>Portal : JSON {connected,user,uptime,bytes_in,bytes_out,time_left}
```

**Diagram sources**
- [api/session.php:50-106](file://api/session.php#L50-L106)
- [includes/db.php:56-116](file://includes/db.php#L56-L116)

**Section sources**
- [api/session.php:1-107](file://api/session.php#L1-L107)

## Dependency Analysis
The security subsystem exhibits clear separation of concerns:

- `admin/login.php` depends on helpers, auth, and csrf modules.
- `admin/logout.php` depends on helpers, auth, and csrf modules.
- `admin/index.php` depends on db and layout modules and enforces authentication via auth.
- `includes/auth.php` depends on config and db modules.
- `includes/csrf.php` depends on config, helpers, and auth modules.
- `includes/crypto.php` depends on config module.
- `api/session.php` depends on helpers, db, and router factory.

```mermaid
graph LR
Login["admin/login.php"] --> Auth["includes/auth.php"]
Login --> Csrf["includes/csrf.php"]
Logout["admin/logout.php"] --> Auth
Logout --> Csrf
Dashboard["admin/index.php"] --> Auth
Auth --> Config["includes/config.php"]
Auth --> Db["includes/db.php"]
Csrf --> Config
Csrf --> Helpers["includes/helpers.php"]
Crypto["includes/crypto.php"] --> Config
SessionApi["api/session.php"] --> Helpers
SessionApi --> Db
```

**Diagram sources**
- [admin/login.php:14-18](file://admin/login.php#L14-L18)
- [admin/logout.php:15-19](file://admin/logout.php#L15-L19)
- [admin/index.php:14-17](file://admin/index.php#L14-L17)
- [includes/auth.php:14-15](file://includes/auth.php#L14-L15)
- [includes/csrf.php:12-14](file://includes/csrf.php#L12-L14)
- [includes/crypto.php:15-15](file://includes/crypto.php#L15-L15)
- [api/session.php:23-25](file://api/session.php#L23-L25)

**Section sources**
- [admin/login.php:14-18](file://admin/login.php#L14-L18)
- [admin/logout.php:15-19](file://admin/logout.php#L15-L19)
- [admin/index.php:14-17](file://admin/index.php#L14-L17)
- [includes/auth.php:14-15](file://includes/auth.php#L14-L15)
- [includes/csrf.php:12-14](file://includes/csrf.php#L12-L14)
- [includes/crypto.php:15-15](file://includes/crypto.php#L15-L15)
- [api/session.php:23-25](file://api/session.php#L23-L25)

## Performance Considerations
- Database performance:
  - SQLite WAL mode improves concurrency.
  - Busy timeout reduces contention under load.
  - Indexes on login attempts and monitor samples optimize queries.
- Session handling:
  - Session regeneration on login prevents fixation attacks with minimal overhead.
  - Idle timeout avoids long-lived sessions unnecessarily.
- CSRF and rate limiting:
  - Token generation uses secure random bytes once per session.
  - Rate limiting queries are indexed and pruned regularly to avoid table growth.

[No sources needed since this section provides general guidance]

## Troubleshooting Guide

Common authentication and security issues:

- Cannot access admin pages:
  - Ensure the session is started and the admin is authenticated.
  - Check for idle timeout redirection; look for the timeout notice parameter.
  - Verify that the session cookie is present and matches the expected name.

- Repeatedly locked out:
  - Review the failed login attempts table for the client IP.
  - Confirm the rate limit thresholds and window settings.
  - Wait for the window to expire or clear the attempts for the IP after a successful login.

- CSRF validation failures:
  - Ensure forms include the hidden CSRF token field.
  - Verify that the session is active before verifying the token.
  - Check that POST requests are used for state-changing operations.

- Logout not working:
  - Confirm logout is triggered via POST with a valid CSRF token.
  - Verify that the session is destroyed and the cookie is cleared.
  - Ensure the redirect goes to the login page.

- HTTPS-related cookie issues:
  - Confirm the server terminates TLS and sets the Secure flag appropriately.
  - Check browser behavior regarding SameSite=Strict cookies.

- Audit logs missing:
  - Verify that audit writes succeed and the table exists.
  - Ensure the admin ID is present for logged-in users.

- Router credential decryption errors:
  - Confirm the key file exists and is readable.
  - Validate the key format (raw 32 bytes, hex, or base64).
  - Check for tampered or malformed encrypted payloads.

**Section sources**
- [admin/login.php:33-35](file://admin/login.php#L33-L35)
- [admin/login.php:54-60](file://admin/login.php#L54-L60)
- [includes/csrf.php:48-67](file://includes/csrf.php#L48-L67)
- [admin/logout.php:21-42](file://admin/logout.php#L21-L42)
- [includes/auth.php:236-246](file://includes/auth.php#L236-L246)
- [includes/crypto.php:25-47](file://includes/crypto.php#L25-L47)
- [includes/crypto.php:111-137](file://includes/crypto.php#L111-L137)

## Conclusion
The administrative panel implements a robust security model centered on hardened sessions, CSRF protection, rate limiting, audit logging, strong password hashing, and encrypted storage of sensitive credentials. By following the documented controls and guidelines, operators can maintain a secure admin interface and mitigate common attack vectors such as CSRF, brute force, session fixation, and credential exposure.

[No sources needed since this section summarizes without analyzing specific files]