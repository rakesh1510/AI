# Phase 4 security audit (P0)

Scope: application code in this repository, reviewed on branch `feat/aihubpedia-global-growth-phase4` (from Phase 3 commit `50c6348`). No production system, credential, database or hosting panel was accessed. Everything below was tested on an isolated local MariaDB/`php -S` instance with synthetic data.

Legend: **Implemented** = code changed and covered by a test · **Proposed** = not done, documented · **Manual** = needs the site owner/hosting access · **Open** = known, unresolved.

## Findings and status

| # | Finding (before) | Severity | Status | Evidence |
|---|---|---|---|---|
| 1 | `db.php` shipped a real-looking database password and username as fallback | Critical | **Implemented**: no fallback. Credentials only from `AIHUB_DB_*` or untracked `config.local.php`; without them `db()` throws and the site uses the JSON catalogue. **Manual**: rotate the exposed password (it is in git history) | `security_test.php` "db() throws instead of using a built-in password"; static scan for the old password |
| 2 | `admin.php` accepted `?pass=` in the URL, defaulted to `admin123`, and **printed the password on the login page** | Critical | **Implemented**: session login with `password_hash` from `AIHUB_ADMIN_PASS_HASH`; disabled when unset; HttpOnly + SameSite=Strict cookie, regenerated id, 30 min idle / 8 h limit, 5 attempts per 15 min, POST + session CSRF for every action, approvals validate the website URL | `security_http_test.php` Admin section (13 checks) |
| 3 | SMTP app password and admin password committed in `data/site.json` | Critical | **Implemented** (working tree blanked; mail settings can come from env). **Manual**: rotate the Gmail app password and admin password; history still contains them | `security_test.php` source scan |
| 4 | `submit_tool_save.php` let anyone append unvalidated entries (including `javascript:` URLs) to the live catalogue | High | **Implemented**: endpoint retired (301 to `/submit.php`); `submit-tool.php` also retired | HTTP test "legacy catalogue writer no longer writes" |
| 5 | `submit.php` stored any website string and had no CSRF/rate limit/honeypot; admin approval copied it into `tools.json` | High | **Implemented**: CSRF token, honeypot, 5 per hour per network, only public http(s) URLs (no credentials, no private/link-local hosts), length caps, errors not leaked | HTTP tests (3) |
| 6 | State-changing **GET** endpoints (`vote-tool.php`, `toggle-bookmark.php`) | High | **Implemented**: GET is inert; bookmark is POST + CSRF | HTTP "GET … changed nothing" |
| 7 | `rate.php`, `vote-tool-ajax.php`, `track-click.php`, `submit-comment.php`, `toggle-bookmark.php`: no CSRF, no rate limit; `rate.php` turned `display_errors` on and returned `$e->getMessage()`; open redirect via `context` | High | **Implemented**: POST only, token (`X-CSRF-Token` or `_csrf`), same-origin check, per-endpoint rate limits, known tool ids only, generic errors (detail goes to the security log), `context` validated as a local path | HTTP "CSRF and origin checks" (14 checks) |
| 8 | Client IP trusted `CF-Connecting-IP`, `X-Real-IP` and `X-Forwarded-For` from anyone | High | **Implemented**: `sec_client_ip()` ignores every forwarded header unless the TCP peer is in `AIHUB_TRUSTED_PROXIES`; then walks `X-Forwarded-For` right to left (or reads one configured header). IPv6 hashed per /64 | 24 unit checks + HTTP test rotating three spoofed headers still hits the limit |
| 9 | `get_ip_hash()` used a hard-coded salt string | Medium | **Implemented**: salted HMAC from the configured secret | source scan |
| 10 | `contact_send.php` stored raw IP and user agent | Medium (privacy) | **Implemented**: stores a salted hash only; 5 messages per hour; header injection stripped from subject; length caps; SMTP errors no longer echoed to visitors | unit/HTTP |
| 11 | `vote_debug.php` printed visitor id, cookie and IP | Medium | **Implemented**: deleted | HTTP: 404 |
| 12 | `install-*.php`, `import-json-to-mysql.php`, `generate_compare_pages.php`, `compare_*.php` reachable over HTTP; `README*.txt`, `PROJECT_STATUS.txt` readable | High | **Implemented**: every script refuses non-CLI requests (404) and is blocked in `.htaccess`/test router; text notes blocked. **Manual**: also block at the web server (see checklist) | unit "refuses non-CLI requests" + HTTP 404 for 16 paths |
| 13 | `affiliate-dashboard.php` displayed invented figures (1,240 clicks, 61 conversions, $480) | Medium (honesty) | **Implemented**: page now states that nothing is tracked and shows only the real count of affiliate links | manual review |
| 14 | `redirect.php` followed whatever URL a record held (`javascript:`, `file:`, credentialed URLs) | Medium | **Implemented**: only plain public http(s) URLs, destination never taken from the request, `noindex`, `no-referrer`, `no-store` | unit (41 URL cases) + HTTP |
| 15 | Missing baseline headers | Low | **Implemented**: `X-Frame-Options`, `Permissions-Policy` (plus existing nosniff/referrer policy). **Proposed**: CSP (pages still use inline styles/scripts), HSTS (needs confirmed HTTPS) | HTTP test |
| 16 | `helpersold.php` (unused legacy copy with the same IP-trust flaw) | Low | **Implemented**: deleted | – |
| 17 | `/signup-redirect` named in the brief | – | **Not present**: no file, route or rewrite with that name exists in this repository (searched all file types). If it exists on the live server (e.g. a hosting-panel redirect), it is outside this code and needs a manual review (checklist item) | grep; HTTP 404 test |

## What was checked and found acceptable
- **SQL injection**: every database query that takes input uses prepared statements; the remaining `query()` calls are static strings or use `PDO::quote`. No string-built SQL from request data was found.
- **XSS**: request values rendered in templates go through `h()`/`htmlspecialchars` (search term, comments, tool fields). A stored `<script>` comment is verified to be output-escaped.
- **Lead and newsletter forms (Phase 3)**: stateless HMAC token with 2-second time trap and 24 h expiry, honeypot, consent checkbox, per-network rate limits, prefetch-safe unsubscribe. Their network hash now uses the trusted-proxy aware IP.

## Residual risks and limits (be aware before relying on this)
1. **CSRF tokens on anonymous endpoints are stateless**, not bound to a visitor. They stop cross-site forged requests but any visitor can fetch a valid token; abuse control there relies on rate limits and honeypots. Admin uses session-bound tokens.
2. **Rate limiting is file based** (`AIHUB_RATE_DIR`, default the system temp dir). It works on a single server; on several servers or an ephemeral filesystem it is per instance and may reset. A shared store (database/Redis) is **proposed**.
3. **Trusted proxies must be configured** (`AIHUB_TRUSTED_PROXIES`, `AIHUB_PROXY_HEADER`) or all visitors behind a CDN share the proxy's address and one hash, so a limit hit affects everyone. Fail-safe but noisy. This is the single most important deployment setting.
4. **Secrets are still in git history.** Code cannot undo that; rotate and, if the repository is or becomes public, purge history.
5. **Legacy demo login** (`login.php`, `data/users.json`) still contains a public demo account. It only grants bookmarks; it now has CSRF, rate limits and session-id regeneration. Removing the demo is proposed.
6. **`data/*.json` remain writable by PHP** (comments, ratings, votes, submissions). Moving them to the database is proposed.
7. **No Content-Security-Policy yet**, and HSTS, TLS and HTTP-to-HTTPS redirects are hosting settings (manual).
8. Third-party favicon requests (Phase 3 risk 5) are unchanged.
9. Not tested: real proxy/CDN behaviour, real mail delivery, penetration testing by a third party, dependency vulnerability scan (`vendor/` PHPMailer version was not audited here).

## Tests added
- `tests/security_test.php` – 144 checks, no server needed (CIDR, proxy trust, redirects, URL validation, tokens, rate limiter, log hygiene, admin hash handling, source scans for secrets/unsafe patterns, install scripts CLI-only, no DB fallback).
- `tests/security_http_test.php` – 65 checks against a running server (CSRF, origin, inert GETs, XSS escaping, rate limiting with rotated forwarding headers, blocked files, redirects, submit validation, admin login/CSRF/logout).
- Both are CI steps in `.github/workflows/ci.yml`.

The manual hosting steps are in [HOSTINGER_SECURITY_CHECKLIST.md](HOSTINGER_SECURITY_CHECKLIST.md). Nothing in it was executed.
