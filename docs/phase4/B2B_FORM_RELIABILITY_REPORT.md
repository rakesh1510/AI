# B2B lead workflow reliability report

Scope: advertiser, affiliate-partner, newsletter-sponsorship, consultation, AI-agent and software-development inquiries (`/inquiry.php`), the notification email to the owner, the lead status workflow and the admin reporting prototype. **All email in these tests is mock**: a local SMTP sink, a closed TCP port, a file outbox and an unwritable path. Nothing left the machine and no real address, credential or customer record was used.
Test file: `tests/lead_reliability_test.php` (48 checks, runs in CI). Related: `tests/security_negative_http_test.php` (form abuse), `tests/db_compat_test.php` (missing tables).

## What was verified
| Stage | Verified behaviour | Result |
|---|---|---|
| Submission | Valid request is stored, visitor is redirected to a thank-you page that does not echo personal data | Pass |
| Validation | Bad email, missing consent, too-short message, missing name, `javascript:` website are refused (HTTP 422) with a visible, accessible field error; input is kept so the visitor can correct it; nothing is stored or sent | Pass |
| Spam controls | Honeypot, missing/forged/foreign-form token, instant submit (time trap), per-network DB rate limit (also with rotated spoofed forwarding headers), mass-assignment fields (`status`, `is_synthetic`, `ip_hash`, `created_at`, `id`) ignored, oversize fields truncated, SQL/markup in fields stored as data and escaped on output | Pass (negative suite) |
| Storage | Lead row with qualification and attribution columns; extra answers appended to the message when the Phase 4 migration is missing | Pass |
| Owner notification | Reaches the mock mail server with the visitor as Reply-To; plain-text part is properly formatted (one field per line); markup in names is escaped; CRLF/Bcc injection in the name cannot add headers or recipients | Pass |
| **Notification failure** | With the mail server unreachable the lead is **still stored**, the visitor **still sees success**, and the failure is recorded as a safe reason code (`smtp_connect`) with an attempt counter | Pass |
| **Failure visibility** | The admin lead page shows a banner ("N leads could not be announced by email… reason: The mail server could not be reached"), a "Not sent" marker in the list and detail view, and a **Retry notification** button. After mail works again the retry sends and records it; the banner disappears | Pass |
| **No sensitive detail** | Neither visitors nor admins see host names, ports, usernames, passwords or raw SMTP text; the security log records lead id and reason code only (no email, name or credentials); credentials never reach the PHP error log; the list masks email addresses (`do***@example.test`) and the full address is shown only in the logged detail view | Pass |
| Retry controls | Retry requires an admin session and a CSRF token; anonymous and cross-origin attempts do nothing; an unknown lead id is refused cleanly | Pass |
| Other failure modes | Unwritable mock outbox is recorded as `outbox_unwritable`; missing `leads` table gives a friendly 503 with the email fallback (and the admin sees "table is not available"); a no-database deployment keeps every page working | Pass |
| Status workflow | new → contacted → qualified → proposal sent → won, each step written to history; an invalid jump (won → new) is refused | Pass |
| Reporting access | Anonymous visitors see only the sign-in form (no lead data in the response); the report total equals the stored lead count; no percentages, ROI or currency amounts are shown | Pass |

## Changes made because of this verification
1. `leads` gained `notify_status`, `notify_attempts`, `notify_error`, `notified_at` (additive; part of `database/phase4_business.sql`; the app works before the migration and shows "Email delivery is not tracked yet").
2. `send_mail()` now classifies failures into fixed codes (`not_configured`, `invalid_recipient`, `library_missing`, `smtp_connect`, `smtp_auth`, `smtp_rejected`, `smtp_other`, `outbox_unwritable`) with plain-language labels; the SMTP server's own text is never stored or shown.
3. Admin: banner, per-lead status and retry (CSRF + session).
4. The notification email now has a correctly formatted plain-text alternative and a subject that cannot be split by a CRLF in the visitor's name.
5. Mail `Message-ID`/`HELO` no longer use the request Host header (found by running the suite on Apache).

## Known limits and recommendations
- **Real email delivery is untested**: SPF/DKIM/DMARC, deliverability to the owner's inbox and spam-folder behaviour depend on the real domain and provider. Send a test lead to a mailbox you own on staging.
- There is no automatic retry queue or alert outside the admin page: if nobody opens `/admin-leads.php`, a failed notification stays unnoticed. Recommended: a daily cron that runs `scripts/business_report.php`-style summary by email, or an uptime monitor on a "failed notifications" count. Not built because it needs real mail credentials.
- The visitor is not told when only the notification failed (the lead is safe). Telling them would add no value and could expose internals.
- Contact-form messages (`contact_send.php`) are stored but their notification failures are only in the security log, not in the admin UI.
- Leads contain personal data: the privacy policy and retention period must be settled (`scripts/purge_leads.php` is dry-run by default).
