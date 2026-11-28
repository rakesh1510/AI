# Phase 7B: intelligent lead management and CEO Overview

Scope: lead response tracking, transparent priority score, one follow-up task per enquiry, owner-only notifications, and an Overview that separates **actual** results from **estimated** opportunities. Reuses the Phase 6 CRM modules, the Control Center, `send_mail_reliable()` + the mail spool and the existing cron pattern. No paid API, no new subscription, no e-mail to customers.

## Rules implemented
| Rule | Implementation |
|---|---|
| Exactly one follow-up task per new enquiry | `crm_lead_ingest()` calls `crm_response_open()` inside the same transaction. `crm_lead_response.lead_id` is the primary key and the row is written with `INSERT IGNORE`; the task is created only when that insert happened, so repeats, retries and the back-fill (`crm_response_sync`) can never create a second one. Test/synthetic leads are not tracked. |
| Response target | 4 business hours: Mon-Fri 09:00-17:00 Europe/Berlin. Excluded: New Year, Good Friday, Easter Monday, Labour Day, Ascension, Whit Monday, German Unity Day, Christmas Day, Second Christmas Day, and International Women's Day (Berlin, from 2019). Extra non-working days: `AIHUB_CRM_EXTRA_HOLIDAYS="2026-12-24,2026-12-31"`. Times are stored in UTC and converted with `Europe/Berlin` (DST tested). |
| First human response | Recorded only when a signed-in user logs an **e-mail reply, call or meeting** on the lead, or schedules/holds a consultation. The first one wins and is never overwritten. The automatic acknowledgement sent to the enquirer is not a CRM activity by a person, so it can never count (tested). Completing the response completes the auto task. |
| Priority | 0-100 from what the enquirer typed (kind, budget, timeline, business e-mail domain, company, website, message length). Every point has a stated reason shown on the lead page. Bands: high 60+, medium 35-59, low below 35. Weights are planning assumptions, not measured win rates. |
| Unanswered / overdue | Lead list filter `response=unanswered|overdue|answered`, columns Priority and Response, Overview tiles and a most-urgent-first list. |
| Overview | Sections: Needs attention; Estimated opportunities (not revenue); Actual results (recorded); Targets. Missing data shows "No data yet" or "Not available yet", never 0. Estimates say ESTIMATE, actuals say ACTUAL (manual records, not bank-verified). Existing permissions unchanged. |
| Owner notifications | `scripts/crm_reminders.php digest|alerts|reminders` to `site_owner_email()` only. Plain text with counts, company names, ids and links; no customer e-mail addresses, no message text. Sent through the existing spool, so a failed SMTP attempt is retried by `mail_spool_flush.php`. |
| No duplicates | `crm_notification_log` has a UNIQUE key (kind, entity_type, entity_id, period_key): digest once per Berlin date; overdue alert once per lead ever; task and stale-proposal reminders once per item per 7-day window. The database enforces it, so parallel cron runs cannot double-send. |

## Database migration (additive, requires approval before running anywhere but the test DB)
Apply after `database/phase6_crm.sql` (and the Phase 8D finance migration if Finance is in use):
`database/phase7b_lead_management.sql`: creates `crm_lead_response`, `crm_consultations`, `crm_notification_log` (all `IF NOT EXISTS`, no foreign keys, no change to any existing table; idempotent).
Rollback: `database/phase7b_rollback.sql` drops only those three tables (this loses response history and notification dedupe records, nothing else).
Before the migration the code behaves as before: enquiries are stored, no task is added, the Overview says the migration is missing, cron jobs exit with "skipped". Existing open enquiries are tracked automatically (last 14 days) the first time the digest/alert job runs after the migration.

## Cron (Europe/Berlin; use the web server user)
```
CRON_TZ=Europe/Berlin
30 8 * * 1-5      www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php digest    >> /var/log/aihub_crm_reminders.log 2>&1
*/30 9-16 * * 1-5 www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php alerts    >> /var/log/aihub_crm_reminders.log 2>&1
0 10 * * 1-5      www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php reminders >> /var/log/aihub_crm_reminders.log 2>&1
```
`--dry-run` prints without sending or recording. The existing `*/5` `mail_spool_flush.php` job delivers retries.

## Operating cost
No new paid service: PHP, MySQL/MariaDB, the existing SMTP account and cron. Added load: three small tables and three short cron jobs (milliseconds, a handful of queries). Mail volume: at most one digest per working day, plus alerts/reminders only when something is overdue.

## Staging deployment (after approval) and rollback
1. Back up the staging DB (keep all existing backups). 2. Deploy the branch to `/var/www/html/AI-PHASE3-TEST` only. 3. Run the migration on `aihub_phase6_test`. 4. Install the three cron lines. 5. Check `/admin-center.php` Overview and a lead page; run `crm_reminders.php digest --dry-run`.
Rollback: remove the cron lines; deploy the previous commit; run `phase7b_rollback.sql` only if the tables must go (leaving them is harmless).
Production: not touched; needs separate approval.

## Limits and risks
* Priority is a rule of thumb on self-reported form fields: it can rank a vague enquiry low; read the reasons and the message.
* "Answered" depends on staff logging the reply, call or meeting in the CRM. A reply sent only from a mailbox is invisible until logged (the lead stays unanswered and appears in the digest).
* Holiday list covers German nationwide holidays and Berlin's; other state holidays, bridge days and company closures need `AIHUB_CRM_EXTRA_HOLIDAYS`.
* Finance tiles show only manually recorded entries (not bank-verified); pipeline value is typed estimates; weighted pipeline uses planning stage weights.
* Mail delivery still depends on the SMTP account; failures are spooled and retried, not guaranteed.
* Pre-existing security finding (public repository, historical credentials) is out of scope here and remains deferred to its own phase.
