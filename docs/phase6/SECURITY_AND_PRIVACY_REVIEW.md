# Security and privacy review (Phase 6)

All Phase 4.1 and Phase 5 controls were preserved and re-tested. No production system, real credential, real e-mail, live database or hosting account was touched. **No critical vulnerability was found in the new code; findings fixed during development are listed.**

## Controls implemented and how they are verified
| Requirement | Implementation | Evidence |
|---|---|---|
| Authentication | `crm_users`, `password_hash`, constant-work login for unknown users, generic failure message | `crm_logic_test`, `crm_http_test` |
| No default credentials | CRM disabled (503) until a user exists; users created only from the CLI (stdin password, at least 12 characters) | HTTP test tries `admin`, `admin123`, `password`, `demo123`; browser test covers the CLI |
| Authorization | Roles in a permission matrix; **every library mutator re-checks the permission**; owner-only approvals, revenue records, audit log and user list | 100+ role checks; viewer and sales POSTs with valid tokens change nothing |
| CSRF and origin | Session-bound token + same-origin on every POST; state never changes on GET; login form uses the existing signed token | HTTP tests (missing, wrong, cross-origin tokens refused) |
| Session security | HttpOnly, SameSite=Strict, Secure on HTTPS, regenerate at login, fixation test, idle and absolute timeouts, user re-read each request | HTTP and logic tests |
| Rate limiting | Login: 5 per 15 min per network, not evaded by forged forwarding headers; public forms: existing per-network limit shared with leads | HTTP tests |
| XSS | All output escaped; hostile names/messages shown inert; subjects stripped of line breaks | HTTP test with script and image payloads |
| SQL injection | Prepared statements throughout; filter values whitelisted; `LIKE` wildcards escaped; injection strings inert | Logic and HTTP tests; hostile barrage extended to the new endpoints (147 checks) |
| Input validation | Strict dates (rejects words PHP's `strtotime` accepts), money (2 decimals), percentages, URLs (existing safe-URL check), emails (header injection refused), whitelisted enums | Logic tests |
| Blocked files | New libraries refuse direct execution and are blocked by `.htaccess` and the test router; the migration and docs return 404 | Inventory (386 checks) and negative tests |
| Sensitive data | Audit summaries scrubbed of emails; security log carries ids only; lists mask emails; thank-you pages echo nothing | HTTP tests inspect the audit and security logs |
| Financial correctness | Integer arithmetic, limits, no float; revenue words separated; received ≤ invoiced | Logic tests incl. property test; static test |
| Safe error handling | Missing tables or users give 503 pages; the CRM mirror is best effort and logged; no stack traces (barrage checks for leaks) | DB-less and negative tests |
| Data minimisation | Business contact details only; no IP or user agent in CRM tables; inbound inquiry is not marketing consent | Schema; tests |
| Privacy notices | Forms carry a consent statement and a privacy link; the services page states what is and is not claimed | Browser tests |
| Audit trails | Login, ingestion, assignment, qualification, conversion, moves, approvals, consent, drafts | Logic and HTTP tests |

## Findings fixed during development (by tests)
| Finding | Severity | Fix |
|---|---|---|
| Money arithmetic overflowed PHP integers at extreme inputs (silently turning into floats) | Medium (financial correctness) | Rounding without doubling; hard limits on quantity, unit price and lines; test at the limits |
| `strtotime()` accepts strings such as "x" as valid dates, so invalid contract dates were accepted | Low–Medium | Strict `YYYY-MM-DD` parser |
| `layout_top.php` overwrote a variable used by the CRM controller, hiding every view | Functional (caught by HTTP tests) | Renamed variable and noted why |
| Scrollable CRM tables not keyboard-focusable (axe) | Low (accessibility) | Focusable labelled regions |
| Anonymous admin matrix and barrage did not cover the new pages | Test gap | Matrix, snapshots and barrage extended |

## Residual risks (honest list)
* **No MFA, no password reset, no account lockout beyond rate limiting.** A strong password chosen by an owner is the only factor. Treat the CRM host as sensitive; consider restricting `/crm.php` by IP at the web server.
* **Shared secrets in sessions files:** PHP session storage must be private on the server (hosting setting).
* **Roles are coarse:** a sales user can read every lead.
* **Retention and erasure** for CRM personal data are not automated; define a policy and a purge procedure before launch. A request to delete a person today needs a manual database action.
* **Public forms remain anonymous** (honeypot, time trap, token, rate limit): determined abusers with many addresses can still submit at the limit rate.
* **Not verified:** real mail delivery, real proxy/CDN behaviour, nginx rules, PHPMailer vulnerabilities, load, third-party penetration test, Apache re-run (not installed in this session).

## Historical credential exposure (values are deliberately not shown)
A scan of all branches' history for private keys, cloud and GitHub tokens found **none**. It found **earlier commits that contain SMTP and database credentials and the old default admin password literal**: `data/site.json` (3 commits, first `3ceb49d`), `README_CONTACT_MAIL.txt` (`3ceb49d`), `helpers.php` (`8ab80e8`), `admin.php` and `README.txt` (admin default; `3ceb49d` and later), plus 13 files across 10 commits mentioning the old admin default, which include documentation that merely *describes* it. Test files contain only test-only values for isolated local databases. These are on `main` and on every older branch. **Rotate the database, SMTP and admin credentials now** and follow `docs/phase4/MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md`. Git history was not modified. Optional later step: purge history with a supported tool after rotation, coordinated with every clone.
