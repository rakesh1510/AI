# Phase 7B staging release plan (website PR #14 + admin PR #15)

Staging only: `https://staging.aihubpedia.com`, directory `/var/www/html/AI-PHASE3-TEST`, database `aihub_phase6_test`. **Production (`/var/www/html/AI`, database `aihub`) is never touched.** Nothing below is run until the owner approves; no step deletes an existing backup, and no credential appears in any command (use the MySQL option file or `sudo mysql`, never `-p<password>`).

Branches: PR #14 `feat/aihubpedia-premium-3d-ui-v3`, PR #15 `feat/phase7b-lead-management`, integration test branch `integration/phase7-website-admin` (both merged, never merged into `main`). Staging should receive the integration branch so the website and the Control Center are tested together.

## 0. Pre-flight (read-only)
```bash
cd /var/www/html/AI-PHASE3-TEST
git status --short                      # must be clean; stop and report if not
git rev-parse --short HEAD              # note the CURRENT commit: this is the rollback target
php -v | head -1; mysql --version
sudo -u www-data crontab -l 2>/dev/null | grep -c crm_reminders   # expect 0
ls /etc/cron.d | grep -i aihub          # note existing cron files, do not edit them
```

## 1. Database backup (new file, existing backups untouched)
```bash
sudo install -d -m 700 /var/backups/aihub-staging
TS=$(date +%Y%m%d-%H%M%S)
sudo mysqldump --single-transaction --routines --triggers aihub_phase6_test | gzip > /tmp/aihub_phase6_test-pre7b-$TS.sql.gz
sudo install -m 600 /tmp/aihub_phase6_test-pre7b-$TS.sql.gz /var/backups/aihub-staging/ && rm /tmp/aihub_phase6_test-pre7b-$TS.sql.gz
ls -l /var/backups/aihub-staging/ | tail -3        # confirm the new file exists and is not empty
gzip -t /var/backups/aihub-staging/aihub_phase6_test-pre7b-$TS.sql.gz && echo BACKUP_OK
```
Also copy the code state: `tar -czf /var/backups/aihub-staging/code-pre7b-$TS.tgz -C /var/www/html AI-PHASE3-TEST` (excluding nothing; keep it, do not prune).

## 2. Deploy code (staging directory only)
```bash
cd /var/www/html/AI-PHASE3-TEST
git fetch origin integration/phase7-website-admin
git checkout --detach origin/integration/phase7-website-admin      # or the commit hash reported in the release report
git rev-parse HEAD
php scripts/build_assets.php --check                                # must print "Built assets are up to date."
for f in crm_response.php crm_core.php adm_core.php crm.php scripts/crm_reminders.php; do php -l $f; done
```
The automatic deploy workflow stays disabled. The website works before the migration (graceful degradation).

## 3. Migration (only after approval) and verification
```bash
sudo mysql aihub_phase6_test < database/phase7b_lead_management.sql      # idempotent, additive, 3 tables
sudo mysql aihub_phase6_test -e "SHOW TABLES LIKE 'crm_lead_response'; SHOW TABLES LIKE 'crm_consultations'; SHOW TABLES LIKE 'crm_notification_log';"   # expect 3 rows
sudo mysql aihub_phase6_test -e "SELECT COUNT(*) AS tracked FROM crm_lead_response; SELECT COUNT(*) FROM crm_leads;"   # tracked is 0 until the first sync/new enquiry
sudo mysql aihub_phase6_test -e "SELECT table_name, column_key FROM information_schema.columns WHERE table_schema='aihub_phase6_test' AND table_name='crm_lead_response' AND column_key='PRI';"   # lead_id
```
Verify in the browser: sign in to `/admin-center.php`, Overview shows "Needs attention" with figures instead of "needs the Phase 7B migration"; open a lead (response panel); `/crm.php?v=leads` shows Priority and Response columns.

## 4. Notifications: DRY-RUN ONLY at first (no e-mail can be sent)
`--dry-run` prints what would be sent and records nothing and sends nothing. Run by hand as the web user:
```bash
sudo -u www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php digest --dry-run
sudo -u www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php alerts --dry-run
sudo -u www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php reminders --dry-run
sudo mysql aihub_phase6_test -e "SELECT COUNT(*) FROM crm_notification_log;"        # must stay 0 after dry runs
ls /var/www/html/AI-PHASE3-TEST/data/mail_spool 2>/dev/null | wc -l                 # spool count must not grow from these runs
```
Cron (NOT installed without approval). The file below is for the approval step and keeps `--dry-run` so even an installed cron cannot send; removing `--dry-run` is a separate approval:
```
# /etc/cron.d/aihub-crm-reminders  (dry-run: prints to the log only)
CRON_TZ=Europe/Berlin
30 8 * * 1-5      www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php digest --dry-run    >> /var/log/aihub_crm_reminders.log 2>&1
*/30 9-16 * * 1-5 www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php alerts --dry-run    >> /var/log/aihub_crm_reminders.log 2>&1
0 10 * * 1-5      www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php reminders --dry-run >> /var/log/aihub_crm_reminders.log 2>&1
```
Going live later (separate approval): remove `--dry-run`, confirm the owner address with `site_owner_email()` is the intended inbox, send one `digest` by hand first, and check `crm_notification_log` and the mail spool. Holiday region defaults to Baden-Wuerttemberg; set `AIHUB_CRM_HOLIDAY_REGION` / `AIHUB_CRM_EXTRA_HOLIDAYS` in the existing environment/config only if needed.

## 5. Rollback
1. Cron: remove `/etc/cron.d/aihub-crm-reminders` if it was installed.
2. Code: `cd /var/www/html/AI-PHASE3-TEST && git checkout --detach <commit noted in step 0>` then `php scripts/build_assets.php --check`.
3. Database (only if the tables must go; leaving them is harmless and keeps history): `sudo mysql aihub_phase6_test < database/phase7b_rollback.sql` (drops only the 3 new tables). Full restore, if ever needed: `gunzip -c /var/backups/aihub-staging/aihub_phase6_test-pre7b-<TS>.sql.gz | sudo mysql aihub_phase6_test`.
4. Confirm: homepage loads, `/admin-center.php` Overview loads, `php scripts/mail_spool_flush.php` still runs, auto-deploy workflow is still disabled.

## 6. Acceptance checks on staging
Homepage, header and V4 globe unchanged; `/admin-center.php` Overview and `/crm.php` leads on desktop and a phone; submit one test enquiry (a test address you control) and confirm exactly one task and a response row; log a reply and confirm the task completes; AI Universe still hidden; `sudo -u www-data php scripts/crm_reminders.php digest --dry-run` prints a digest.
