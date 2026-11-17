# Release readiness: Phase 4.1 summary

**Goal:** make Phase 4 technically ready for a *controlled staging review* without risking production, Hostinger, customer data or credentials. Nothing was merged or deployed; no live system was touched.

## Verdict
**Ready for a controlled staging review, not for production.** The code-level launch blockers found so far are fixed and covered by automated tests that run in CI. What remains needs the site owner (credentials, server settings, legal text, editorial verification) and cannot be done from the repository.

## What this phase closed
| Area | Result |
|---|---|
| Security verification | Independent inventory of all 106 PHP files, secrets scan of 543 tracked files, 123 negative HTTP checks, Apache validation; 14 additional findings fixed (SECURITY_VERIFICATION_REPORT.md) |
| Performance | Related-link cost on reviewed tool pages 187 ms → 19 ms (Apache TTFB); every search ~40% faster; scaling and invalidation tested (PERFORMANCE_BENCHMARK.md) |
| Database compatibility | Read-only preflight + migration plans; 7 synthetic scenarios incl. "no database" (DATABASE_COMPATIBILITY_REPORT.md); fixed an HTTP 500 on `/redirect.php` without a database |
| B2B lead reliability | End-to-end with mock mail; failed notifications are stored, visible to the admin and retryable without exposing sensitive detail (B2B_FORM_RELIABILITY_REPORT.md) |
| Catalogue quality | All 509 profiles audited, prioritised queue of 19 P1 / 11 P2 / 479 P3 (EDITORIAL_VERIFICATION_QUEUE.md, CSV) |
| Release docs | Credential rotation checklist, Hostinger hardening checklist, safe staging instructions, rollback and deployment plans |

## Blockers that remain (owner action; none can be done by code)
| # | Blocker | Why it blocks production | Where documented |
|---|---|---|---|
| 1 | **Rotate the database, SMTP and admin credentials.** They are in Git history and at the tip of `main` and every older branch | Anyone with repository access (or a clone) has them | MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md |
| 2 | **Reconcile the live database** with the code (the committed install SQL for votes/clicks is outdated; `contact_messages` has no SQL) | Otherwise votes/clicks stay unavailable and forms may not save | DATABASE_COMPATIBILITY_REPORT.md |
| 3 | **Server configuration**: trusted proxies, HTTPS/HSTS, block rules on non-Apache servers, private rate/cache dirs, opcache | Rate limits and access rules depend on it | HOSTINGER_SECURITY_CHECKLIST.md |
| 4 | **Legal**: privacy policy (leads, newsletter, storage, analytics), Impressum vs TrustNova Tech entity | Forms collect personal data | RELEASE_RISKS (Phase 3) |
| 5 | **Editorial**: 0 of 509 profiles are verified by a person; unreviewed profiles carry template features/pros/cons and unsourced prices | Trust claims must match reality | EDITORIAL_VERIFICATION_QUEUE.md |
| 6 | **Real mail test** (SPF/DKIM/DMARC) and an operational routine for checking failed notifications | Leads can be missed if nobody opens the admin page | B2B_FORM_RELIABILITY_REPORT.md |
| 7 | Pricing/package decisions (€199/€499) and a launched, measured newsletter before selling sponsorship | No audience data exists | B2B_REVENUE_STRATEGY.md |

## Not verified in this phase
Real proxy/CDN behaviour; real email delivery; nginx/LiteSpeed rules; the PHPMailer dependency's vulnerability status; load/capacity; screen-reader testing; any ranking, traffic or conversion outcome; the live database.

## Evidence
Final test results: TEST_RESULTS.md. Everything runs in `.github/workflows/ci.yml`.
