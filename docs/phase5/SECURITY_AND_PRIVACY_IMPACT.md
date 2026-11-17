# Security and privacy impact (Phase 5)

All Phase 4.1 controls were preserved and re-tested (see TEST_RESULTS.md). No credential, real email, live database, hosting system or external service was touched. **Reminder: historical credentials in Git history still need rotating by the owner** (`docs/phase4/MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md`).

## New or changed surfaces
| Surface | Change | Controls | Residual risk |
|---|---|---|---|
| `/admin-editorial.php` (new, admin) | Review queue, ledger, review form | `admin_require()` before any output; POST only with session CSRF and same-origin; negative test posts forged verify requests anonymously and asserts no state change; classified `admin` in the security manifest | Reviewer identity is self-declared (single admin password) |
| `data/editorial_review_log.json` (new write path) | Append-only JSON, file-locked | Written only by `editorial_log_append()` after validation; control characters stripped, lengths capped, values escaped on output; `data/` is not web-readable; path overridable only by server environment | Writable by PHP like the other JSON stores; not tamper-proof; back it up |
| `editorial_review.php`, `i18n.php` (new libraries) | Logic only | Refuse direct execution; blocked in `.htaccess` and the test router; inventory test classifies them as libraries | None new |
| `tool.php`, `compare.php` | Evidence ledger and status rows | Output escaped; source links `rel="noopener nofollow"` and only vendor URLs that pass the safe-URL check | None new |
| `data/locales.json`, `data/translations.json` | Registry only | No locale is routed; planned locales are not recognised in URLs | A future live locale must be validated by `locale_validate_set` |
| `search` ranking | Template text removed; task verbs ignored; reviewed bonus conditional | Deterministic; labelled reasons unchanged | None |
| `multilingual-demo.php` | Replaced unreviewed machine translations with an English notice | Stays noindex | None |
| `advertise.php` | Reporting transparency text | Static text | None |

## Findings during Phase 5
No critical or high security finding. Notes: (1) the negative test matrix was extended to the new admin page; (2) a Phase 4.1 inventory expectation remains intact (every top-level PHP file classified: now 109 files, 348 inventory checks pass).

## Privacy impact
* **Visitor data:** none new. The ledger and comparison changes read catalogue data only. Saved tools and recent views remain browser-only.
* **Staff data:** the review log stores a reviewer name and timestamp (personal data of editors). Tell editors it is recorded; keep access to the admin limited; define retention.
* **Analytics:** unchanged and off by default.
* **i18n:** no cookies or language detection; locale comes from the URL only (no IP-based or header-based redirection).
* **Newsletter / B2B:** unchanged; mock sending only; lead notification handling as in Phase 4.1.

## Not verified
Real proxy/CDN behaviour, real mail, nginx rules, PHPMailer vulnerabilities, load, third-party penetration testing.
