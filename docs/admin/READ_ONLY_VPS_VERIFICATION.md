# Read-only verification commands for the VPS (to be run by the administrator)

These commands only **read**. I (the development assistant) have not run them and will not run them against any server. They answer: which code is deployed, which database tables exist, which accounts exist, and how the legacy admin password is configured. Run on **staging** first. Never paste passwords or hashes into chat.

## 1. Which code is running
```
cd /var/www/html/AI-PHASE3-TEST            # staging checkout path used earlier; confirm the real path
git rev-parse HEAD
git status --porcelain                      # empty means no local edits
git log --oneline -3
ls -1 admin-center.php adm_analytics.php adm_tools_core.php adm_finance_core.php adm_nav.php 2>&1
```
Compare the commit with: Phase 8A `818d93c`, 8B `8aa56ce`, 8C `9e063ad`, 8D `7c4b35f` (later documentation-only commits do not change the code). A missing file means that phase is not deployed.

## 2. Which database tables exist (migrations applied)
Run as a **read-only** database account:
```
SELECT VERSION();
SELECT DATABASE();
SHOW TABLES LIKE 'crm\_%';
SHOW TABLES LIKE 'uni\_%';
SHOW TABLES LIKE 'adm\_%';
SHOW TABLES LIKE 'fin\_%';
```
Code can be present without its tables: the Control Center then shows "storage not installed".

## 3. Which accounts exist (no passwords are shown)
```
php scripts/crm_user.php list
```
Shows usernames, display names, roles and the active flag. Confirm a named **owner** account exists and is active. If none exists, create it with the documented command, supplying the password on standard input in your own terminal (never in chat, history or a script).

## 4. Environment settings (names only; never print values)
```
env | cut -d= -f1 | grep -E '^AIHUB_' | sort
```
For the web server, check the virtual host or PHP-FPM pool for: `AIHUB_ENV_LABEL` (set to `staging` or `production`), `AIHUB_ADMIN_PASS_HASH` (legacy shared password: presence only, never the value), `AIHUB_ANALYTICS` (must be unset unless tracking was approved) and `AIHUB_FORM_SECRET`.

## 5. In the browser
- The sidebar shows "Phase 8D" next to Finance when the code is older than 8D, and "Phase 8C" next to AI tool management when older than 8C.
- Every page shows the environment banner once `AIHUB_ENV_LABEL` is set (amber STAGING, red PRODUCTION); without it the banner says "ENVIRONMENT NOT SET".
- The name and role next to "Sign out" show who you are signed in as; sign out and sign in with the intended account if it is the wrong one.

## 6. Report back
The commit hash, the files present, the table names found, the account list (usernames and roles only), and which environment settings exist. Nothing else.
