# Security Plan

| Threat | Control in NextaCRM | Where |
|---|---|---|
| SQL injection | PDO prepared statements only; identifiers (tables/columns/sort fields) come from entity metadata whitelists, never from input | `Core/DB.php`, `Services/RecordService.php` |
| XSS | Every template value goes through `e()` (htmlspecialchars, UTF-8, ENT_QUOTES); JSON for Alpine is embedded with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`; rich text is not rendered as HTML (plain text + safe line breaks/links) | `Core/View.php` |
| CSRF | Per-session token; required on every non-GET web request (form field or `X-CSRF-Token` header); SameSite=Lax cookies | `Core/Csrf.php` |
| Session hijacking / fixation | `session_regenerate_id(true)` on login, 2FA and privilege change; HttpOnly, Secure (when HTTPS), SameSite; idle + absolute timeouts; session bound to a DB row so "log out all sessions" and admin revocation work; user-agent fingerprint check | `Core/Session.php`, `Core/Auth.php` |
| Brute force | Failed attempts counted per IP, per username and per 2FA code within a window (Espo pattern); progressive lockout; generic error messages | `Core/Auth.php`, `login_attempts` |
| Password storage | Argon2id (`PASSWORD_ARGON2ID`) with bcrypt fallback if Argon2 unavailable; `password_needs_rehash` on login; minimum length + breached-pattern checks | `Core/Auth.php` |
| 2FA | TOTP (RFC 6238, ±1 step) + 10 single-use recovery codes (hashed); admins can enforce 2FA | `Core/Totp.php` |
| Privilege escalation / IDOR | Every record access goes through `Permissions::can()` + scope SQL (all/team/own) + company filter; relation IDs on save are verified; admins only can edit roles; users cannot edit their own role | `Core/Permissions.php`, `RecordService` |
| Tenant leakage | `company_id` injected server-side on read/write; company switch validated against `company_user` | `RecordService` |
| SSRF | Webhooks, workflow HTTP actions, updater and storage tests use `HttpClient`: http/https only, DNS resolved, private/loopback/link-local/reserved IPv4+IPv6 blocked, IP pinned with CURLOPT_RESOLVE, redirects disabled, 10 s timeout, 2 MB response cap | `Core/HttpClient.php` |
| Path traversal | Storage keys generated server-side; driver layer rejects `..`, absolute paths, NUL; local driver verifies `realpath` stays under root | `Core/Storage/*` |
| Malicious uploads | Extension allow-list + finfo MIME sniff must agree; executable/script types blocked (php, phtml, phar, svg, html, js, exe …); size limit; stored outside web root semantics (denied dir) with random names; served with `Content-Disposition: attachment` (inline only for images/PDF) and `X-Content-Type-Options: nosniff` | `Services/FileService.php` |
| Open redirect | `redirect()` accepts only relative paths starting with a single `/`; `next=` parameters validated | `Core/Response.php` |
| Unauthorized API access | Bearer tokens 40 random bytes, stored as SHA-256, scope (read/write), expiry, last-used; per-token rate limit; same permission engine as UI | `Http/Controllers/Api/*` |
| Clickjacking | `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` (web forms endpoint is the exception and only serves the form) | `Core/SecurityHeaders.php` |
| Content injection | CSP: `default-src 'self'; script-src 'self' 'unsafe-eval'` (required by Alpine's expression evaluator — no inline scripts are used, all JS is in files); `style-src 'self' 'unsafe-inline'`; `img-src 'self' data: blob:`; `connect-src 'self'`; `object-src 'none'`; `base-uri 'self'`; `form-action 'self'` | same |
| Transport | HSTS when served over HTTPS; Secure cookies | same |
| Secrets at rest | SMTP passwords, storage keys, webhook secrets encrypted with libsodium `secretbox` using the app key from `config.php` | `Core/Crypto.php` |
| Information disclosure | `display_errors=0` in production; errors logged with request ID; user sees generic page with the ID; config & storage dirs denied; API never returns password/secret columns (metadata `hidden`) | `Core/ErrorHandler.php` |
| Installer abuse | `storage/installed.lock` blocks installer; config written with 0640 perms; installer refuses to run if config exists | `install/` |
| Update tampering | Ed25519 signature + SHA-256 verification before extraction; ZIP entries checked for traversal | `Core/Updater.php` |
| Workflow abuse | No code-execution action; email/webhook actions rate-limited; recursion guard (max depth 3) | `Services/WorkflowService.php` |
| Web form spam | Honeypot field, minimum fill time, per-IP rate limit, form token | `Http/Controllers/PublicController.php` |
| Audit | Login success/failure, permission changes, settings changes, record create/update/delete with field diffs, exports | `audit_logs` |
| Dependencies | Only 8 vendored libraries pinned at release tags; documented update procedure; `composer audit` can be run by maintainers | `vendor/VERSIONS.md` |

## Operational checklist (in README)
1. Force HTTPS in cPanel. 2. Set the cron line. 3. Enforce 2FA for admins. 4. Choose a storage driver and test it. 5. Configure SMTP with SPF/DKIM. 6. Keep `storage/backups` off-site.
