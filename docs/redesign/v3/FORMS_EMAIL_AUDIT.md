# Forms and e-mail workflow audit (staging) and fixes on the V3 branch

Scope: staging only. Nothing here is deployed. No production file, database, DNS, Apache or credential was touched. AI Universe stays hidden.
Test method: isolated local database (`aihub_test`), mock mail only (file outbox, or a closed SMTP port to simulate an outage). No real address was e-mailed.

## 1. Why you received no e-mail (most likely cause, to be confirmed with the read-only commands in section 6)

| Layer | Finding |
|---|---|
| Config in the repository | `data/site.json` points at `smtp.gmail.com:587` with **empty SMTP user and password**. Gmail rejects unauthenticated mail, so every send fails with `smtp_auth` / `smtp_other`. |
| Application behaviour (V2 and V3 identical) | The enquiry is saved, the visitor sees the success page, the failure was recorded only on the `leads` row (`notify_status=failed`) and the security log. There was **no retry, no queue, no alert**, so the enquiry was silently "lost" as far as the inbox is concerned. |
| Staging override | Real credentials live outside the repository (`config.local.php`, `site_settings`, or `AIHUB_SMTP_*` env). If staging has none of these, or `AIHUB_MAIL_TRANSPORT=file` is set, no mail leaves the server. Only the server output can confirm which. |
| V3 regression? | **No.** V3 changed no mail, form or storage logic (only markup/CSS/JS around the forms). The same code behaves the same on V2. |

## 2. Per-form status (before this change -> after this change)

| Form | Validation | Stored | Reference | Owner mail | Customer ack | Retry | Dedupe | CSRF/spam |
|---|---|---|---|---|---|---|---|---|
| Discuss your project / custom AI (`project-inquiry.php`) | yes | `leads` + CRM | none -> **AHP-L** | sent once, failure unseen -> **spooled + retried** | none -> **yes** | none -> **cron** | none -> **15 min** | signed token, 2 s min age, honeypot, consent, 5/h/IP |
| AI consultation, advertise, sponsorship (`inquiry.php`) | yes | `leads` + CRM | none -> **AHP-L** | same | same | same | same | same |
| Partnership (`partner-apply.php`) | yes | `leads` + CRM | none -> **AHP-L** | same | same | same | same | same |
| Contact us (`contact_send.php`) | yes | `contact_messages` (**no CREATE TABLE in the repo**) | none -> **AHP-C** | refused if SMTP user empty, failure shown as error -> **saved, acknowledged, spooled** | none -> **yes** | none -> **cron** | none -> **15 min** | session CSRF, same-origin, honeypot, rate limit |
| Submit an AI tool (`submit.php`) | yes | `submissions` | none -> **AHP-S** | **never sent** -> **now sent** | n/a (no email field) | none -> **cron** | none -> **15 min** | session CSRF, honeypot, 5/h |
| Newsletter (`subscribe.php`, `newsletter-confirm.php`) | yes | `subscribers` (pending, double opt-in) | n/a | n/a | confirmation mail lost on failure -> **spooled + retried** | none -> **cron** | existing | token, honeypot |

## 3. What was changed (V3 branch, uncommitted at the time of writing)

- `mailer.php`: reliable delivery layer. `send_mail_reliable()` sends now; on failure the message is written to a private spool (`data/mail_spool`, 0700/0600, web-blocked). `mail_spool_flush()` retries with back-off 5, 15, 60, 180, 360, 720, 1440 min, up to 12 attempts / 7 days, then moves to `dead/` and logs. `mail_config_report()` and `mail_smtp_probe()` expose booleans/connect-auth results only, never secrets.
- `conversion.php`, `crm_intake.php`: enquiry references, 15-minute duplicate suppression, customer acknowledgement (turn off with `AIHUB_MAIL_CUSTOMER_CONFIRM=0`), owner mail carries the reference and `Reply-To` is the sender.
- `contact_send.php`, `contact.php`, `submit.php`, `inquiry.php`, `project-inquiry.php`, `partner-apply.php`, `leads_admin.php`: same features per form; the contact form no longer refuses a message just because mail is down.
- `scripts/mail_spool_flush.php` (cron, CLI only) and `scripts/mail_health.php` (read-only health report, optional `--probe`). Both registered as `cli` in `tests/security_manifest.json`.
- **No database schema change.** Optional, additive and NOT run: create `contact_messages` with `CREATE TABLE IF NOT EXISTS` from `tests/fixtures/interactions_current.sql` if the staging table is missing (check with the commands below first).

Honest limits: the acknowledgement/owner e-mails still need working SMTP credentials; the spool only guarantees nothing is lost and is retried. If credentials stay wrong the mail waits in the spool (visible with `mail_health.php`) until they are fixed, then goes out. The customer acknowledgement makes no response-time promise.

## 4. Test evidence (isolated DB, mock mail)

- `tests/forms_email_e2e_test.php`: **41 passed, 0 failed.** Covers every form above: validation errors, honeypot, forged token/CSRF, storage, CRM mirror, reference shown on the thank-you page without personal data, owner and customer mail contents, duplicate suppression, SMTP-down (closed port) -> stored + `notify_status=failed` + safe reason code `smtp_connect` + spooled mail + log without address/password, cron flush delivers everything, lead flips to `sent`, no duplicate if the admin already re-sent, health report contains no secret.
- Bug found and fixed by that test: `scripts/mail_spool_flush.php` did not load `db.php`, so retries delivered mail but never marked the lead as `sent`.
- Earlier in this task `lead_reliability_test` passed 56/0 with the new mail code.
- **Not yet re-run after the final fix (sandbox failure, see section 7):** `lead_reliability`, `crm_http`, `crm_logic`, `phase4_logic`, `security_inventory` (new manifest entries), `security_http`, `security_negative_http`, `seo`, `premium_ui`, `universe_hidden`. These must be re-run before the commit is treated as final.

## 5. Spam, CSRF, logging

Lead forms: HMAC-signed form token (2 s minimum, 24 h maximum age), honeypot `website_url_confirm`, consent, minimum message length, per-IP hashed rate limit (5/hour). Contact/submit: session CSRF, same-origin check, honeypot, rate limit. Logs record reason codes (`smtp_connect`, `smtp_auth`, `smtp_rejected`, ...), never an address, message text or password.

## 6. Read-only SSH commands for staging (run one at a time, send the output; none prints a secret)

```bash
export STG=/var/www/html/AI-PHASE3-TEST
cd "$STG" && pwd -P && git rev-parse HEAD

# 1. Which mail settings exist (NAMES and lengths only, never values)
php -r 'chdir(getenv("STG")); require "helpers.php"; foreach(["AIHUB_SMTP_HOST","AIHUB_SMTP_PORT","AIHUB_SMTP_SECURE","AIHUB_SMTP_USER","AIHUB_SMTP_PASS","AIHUB_MAIL_FROM","AIHUB_MAIL_TO","AIHUB_MAIL_TRANSPORT"] as $k){ $v=(string)(getenv($k)?:""); echo $k,"=",$v===""?"unset":"set(len ".strlen($v).")","\n"; }'
test -f config.local.php && grep -o "^[[:space:]]*['\"][A-Za-z0-9_.]*['\"][[:space:]]*=>" config.local.php | sort -u || echo "no config.local.php"
ls -l vendor/autoload.php 2>&1 | cut -c1-80

# 2. Database view (SELECT only). Use your usual mysql login for aihub_phase6_test
mysql aihub_phase6_test -e "SELECT setting_key, LENGTH(setting_value) AS len FROM site_settings WHERE setting_key IN ('mail','smtp','site');"
mysql aihub_phase6_test -e "SELECT notify_status, COUNT(*) n FROM leads GROUP BY notify_status;"
mysql aihub_phase6_test -e "SELECT id, created_at, lead_type, notify_status, notify_attempts, notify_error FROM leads ORDER BY id DESC LIMIT 15;"
mysql aihub_phase6_test -e "SHOW TABLES LIKE 'contact_messages'; SHOW TABLES LIKE 'submissions'; SHOW TABLES LIKE 'subscribers';"
mysql aihub_phase6_test -e "SELECT COUNT(*) contact_messages FROM contact_messages;" 2>&1 | head -3

# 3. Failure reasons in the security log (reason codes only)
ls -l data/*.log 2>/dev/null
grep -h -E "lead_notification_failed|contact_notification_failed|mail_failed" data/*.log 2>/dev/null | tail -20

# 4. Can the server reach the SMTP host/port (no login, no mail sent)
timeout 6 bash -c 'exec 3<>/dev/tcp/smtp.gmail.com/587 && echo "port 587 reachable"' 2>&1 | head -2
```

After the fix is deployed (only after you approve): `php scripts/mail_health.php` (and `--probe` to test connect+login without sending), then add the cron line below. Do not apply either before approval.

```
*/5 * * * * www-data php /var/www/html/AI-PHASE3-TEST/scripts/mail_spool_flush.php >> /var/log/aihub_mail_spool.log 2>&1
```

## 6b. How the mail settings are actually loaded (read from `helpers.php`, `security.php`, `mailer.php`)

The mailer reads `site_cfg()['mail']`, which is built in this order (highest priority first):
1. **Environment variables** `AIHUB_SMTP_HOST/PORT/SECURE/USER/PASS`, `AIHUB_MAIL_FROM`, `AIHUB_MAIL_TO` (only when non-empty). Apache/PHP-FPM variables are NOT visible to the CLI.
2. **`config.local.php`**, key `'mail' => ['smtp_host'=>..., 'smtp_port'=>..., 'smtp_secure'=>..., 'smtp_user'=>..., 'smtp_pass'=>..., 'from_email'=>..., 'to_email'=>...]` (only when non-empty).
3. **Database table `site_settings`, row `mail`** (JSON). If `site_settings` has ANY row, **`data/site.json` is ignored completely**.
4. **`data/site.json`** `mail` block (repository default: `smtp.gmail.com:587`, empty user/password).
`AIHUB_MAIL_TRANSPORT=file` (or `mail_transport` in `config.local.php`) switches to the mock outbox: nothing is sent.

Consequences that explain "SMTP is configured but nothing arrives":
- credentials saved only in `data/site.json` are ignored once staging's database has `site_settings` rows;
- credentials set only as environment variables for Apache are invisible to cron/CLI (retries) unless also set there;
- `smtp_secure` empty with port 587 used to disable STARTTLS entirely (V2 and V3 alike); fixed by `mail_smtp_secure_mode()` (empty + 587 = STARTTLS, 465 = SSL, stored values never modified);
- `to_email` empty or invalid: the enquiry is stored, nobody is notified (now spooled, with a visible reason).
`php scripts/mail_health.php` prints, per setting, which layer supplies it (labels only).

## 7. Status and blockers

- Work is on the V3 branch working tree, **not yet committed or pushed**, **not deployed**.
- The cloud sandbox lost its system libraries partway through the final regression run (`/lib64` missing), so `git commit`, `git push` and the remaining regression suites could not be run in that session. Re-run them in a fresh session before the final commit ID is issued.
- Awaiting your approval before any deployment or merge.
