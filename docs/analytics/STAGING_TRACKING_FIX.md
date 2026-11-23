# Staging Analytics: root cause, fix and the single VPS procedure

Branch `feat/aihubpedia-analytics`. **Live staging status: NOT YET VERIFIED.** I have no access to `srv1424046` or to https://staging.aihubpedia.com from this environment, so I cannot claim the live site is fixed. Everything below marked VERIFIED was proven on a disposable local server with the real application code over real HTTP. The remaining live checks need the VPS administrator (one block below).

## 1. Verified root cause (in the code)
`analytics_enabled()` was `getenv('AIHUB_ANALYTICS') === '1'`, and the salt checks and the hash window also used raw `getenv()`. They never consulted `config.local.php`. Consequences, matching every symptom:
- A value exported in a root shell makes the **CLI** say "Analytics = 1", but **PHP-FPM workers do not inherit a shell's environment** (the pool normally has `clear_env = yes`), so the web application never saw it. As `www-data` the same check says MISSING.
- No amount of editing `config.local.php` could ever enable tracking: the tracking switch had no config-file path at all.
- The environment banner (`AIHUB_ENV_LABEL`) does read the config file, but only from the **top-level key `env_label`**. Keys placed inside the `db` array or written in upper case (`AIHUB_ENV_LABEL`) are never read (a unit test reproduces both mistakes), hence "ENVIRONMENT NOT SET".
- The recorder itself, the table check, the database connection and the public pages (`layout_top.php` calls the recorder on every public page) were verified to work once the switch is on.
Secondary finding: outbound clicks wrote `tool_clicks` only when **both** `tool_clicks` and `tool_votes` existed, so the missing `tool_votes` silently stopped click rows (events were still recorded).

## 2. The fix (committed)
- `analytics.php`: tracking switch, salt and hash window now read through `sec_setting()` (environment first, then the private config file keys `analytics`, `analytics_salt`, `analytics_hash_window`). Only `1` or `true` turn tracking on; empty, missing or anything else is OFF (safe default). Production is unaffected: nothing sets those keys there.
- `helpers.php`/`outbound_click.php`: `tool_clicks_writable()` checks only `tool_clicks` (independent of `tool_votes`).
- `scripts/staging_config_set.php`: dry-run-by-default repair tool. Moves misplaced keys to the top level, sets `env_label=staging`, `analytics=1`, generates a 64-character salt if none is valid, keeps every other key (database credentials untouched), backs up first, writes atomically, keeps owner, group and mode (never widens), refuses unless the configured database is the staging database and never `aihub`, prints no secret. `--disable` switches tracking off (rollback).
- `scripts/analytics_config_check.php`: shows what the **web server** would see (ignores the calling shell's variables), checks file readability, database name, tables, writability, and flags missing trusted-proxy configuration; prints no secret.
- Control Center System health now shows the environment label, whether the private config file is readable by the web worker, and the web worker's user: the real FPM view, not the CLI's.

## 3. VERIFIED locally (real HTTP, empty environment, config file only; disposable MariaDB 10.11, not MySQL 8.4)
`tests/analytics_e2e_http_test.php`: **47 passed, 0 failed**; `tests/analytics_config_test.php`: **193 passed, 0 failed**.
- **Fault reproduced:** with settings in the wrong place (as on staging) the web app writes no page views. After the tool repairs the file, the same server records them.
- One eligible request = **exactly one** `page_views` row; four requests = four rows; same visitor and browser share one daily hash, another browser gets a different hash; 4 views = 2 daily visitors.
- Bots: recorded as bots, **no visitor hash**, excluded from visitor counts. Do-Not-Track and Global Privacy Control: counted as page views, **no hash**.
- No IP or user-agent column; no IP, `Mozilla`, `AppleWebKit` or bot string anywhere in the rows.
- Timestamps and `day` are **UTC even with the web server's PHP set to Asia/Tokyo**.
- Hash rotation: same inputs on the same day equal, next day different.
- Outbound click **without `tool_votes`**: 302 to the official site, no cookie, exactly one `events` row and one `tool_clicks` row with empty identifiers; an immediate repeat and the old `tracked=1` link not counted again; a bot and a prefetch not counted; the redirect is not a page view.
- Rollup with only the config file: 6 human views, 2 bot views excluded, 2 daily visitors, 2 views without id, 1 outbound click; Overview, Visitors, Pages, Sources, AI tool clicks, Categories, Featured placements, Legacy click records and Exports render real numbers (the "No tracking data yet" notice is gone); CSV export has the recorded day; "Conversions and lifetime counts" (the earlier page) is unchanged code.
- A process with an **empty environment** in the application directory sees `STAGING`, tracking on, valid salt.
- The web database account holds no DELETE right on the raw table (least privilege).
Regression: analytics 49 + 68 + 106 + 41, security 147 and inventory 534, Unified Admin, Finance, tools, data quality: all passing; lint clean.

## 4. One copyable VPS block (staging only; run as root or a sudoer on `srv1424046`)
```bash
set -euo pipefail
APP=/var/www/html/AI-PHASE3-TEST ; CFG=$APP/config.local.php ; WEBUSER=www-data
cd "$APP"

# 0. Confirm what the web server really uses (read-only)
ps -eo user,pid,args | grep -E 'php-?fpm|apache2|nginx' | grep -v grep | head
grep -RniE 'clear_env|^user *=|^group *=|env\[' /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head
grep -RniE 'root |DocumentRoot' /etc/nginx/sites-enabled /etc/apache2/sites-enabled 2>/dev/null | grep -i -E 'staging|AI-PHASE3' || echo "check the virtual host root manually: it must be $APP"
ls -l "$CFG"; stat -c '%U:%G %a %n' "$CFG"
git -C "$APP" rev-parse --short HEAD

# 1. Update the STAGING code only (no production directory is touched)
git -C "$APP" fetch origin feat/aihubpedia-analytics && git -C "$APP" checkout feat/aihubpedia-analytics && git -C "$APP" pull --ff-only
git -C "$APP" rev-parse --short HEAD            # must print the commit given in the report

# 2. Dry run, then apply the private-config repair (staging database only; prints no secret)
php scripts/staging_config_set.php --config="$CFG" --staging-db=aihub_phase6_test
php scripts/staging_config_set.php --config="$CFG" --staging-db=aihub_phase6_test --apply
stat -c '%U:%G %a %n' "$CFG"                    # owner/group/mode must be unchanged and readable by www-data (for example root:www-data 640)

# 3. Reload PHP-FPM so no worker or opcache keeps the old file/code
systemctl reload "$(systemctl list-units --type=service --no-legend 'php*-fpm*' | awk '{print $1}' | head -1)"

# 4. Check as the web user, ignoring the shell's environment
sudo -u "$WEBUSER" php scripts/analytics_config_check.php --config="$CFG" --expect-db=aihub_phase6_test --expect-env=staging

# 5. Real HTTP visits against staging (test traffic from this host)
for i in 1 2 3; do curl -s -o /dev/null -w "%{http_code} " -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124 Safari/537.36" https://staging.aihubpedia.com/ ; done; echo
curl -s -o /dev/null -w "bot:%{http_code}\n" -A "Googlebot/2.1" https://staging.aihubpedia.com/
curl -s -o /dev/null -w "dnt:%{http_code}\n" -H "DNT: 1" -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124 Safari/537.36" https://staging.aihubpedia.com/
curl -sI https://staging.aihubpedia.com/ | grep -iE 'cf-cache-status|x-cache|age:|server:' || true     # a CDN or page cache serving stored HTML would stop PHP running: report any HIT
sleep 2
mysql aihub_phase6_test -e "SELECT id, viewed_at, day, path, page_type, status, referrer_class, is_bot, visitor_hash <> '' AS has_hash FROM page_views ORDER BY id DESC LIMIT 10; SELECT UTC_TIMESTAMP();"
# expected: 3 human rows (same hash), 1 bot row (is_bot=1, no hash), 1 DNT row (no hash); no IP or user agent column

# 6. Rollup once by hand (NO cron is installed), as the web user so file/database rights are the real ones
sudo -u "$WEBUSER" php scripts/adm_rollup.php --days=1
mysql aihub_phase6_test -e "SELECT * FROM adm_traffic_daily; SELECT * FROM adm_rollup_run ORDER BY id DESC LIMIT 2;"

# 7. Browser: https://staging.aihubpedia.com/admin-center.php?m=analytics (owner). Expect the amber STAGING banner, KPI numbers, no "No tracking data yet".
```
If step 4 prints `trusted proxies configured ... 0` and the site sits behind a proxy or CDN, tell me: visitors would share one address and be under-counted; the fix is the `trusted_proxies` config key (a separate small step).

## 5. Rollback (any time)
`php scripts/staging_config_set.php --config=$CFG --staging-db=aihub_phase6_test --apply --disable` (tracking OFF at once; table data kept), or restore the printed backup file (`cp -p $CFG.bak-<timestamp> $CFG`), then `systemctl reload php*-fpm`. Code rollback: `git -C $APP checkout 1025a81` (the previously installed commit). Nothing here touches production, drops or deletes any table or row.

## 6. PASS/FAIL checklist (live items cannot be marked PASS until the block above has run)
| # | Item | Status |
|---|---|---|
| 1 | Root cause identified in code | PASS (verified, reproduced by test) |
| 2 | Code fix committed, automated tests pass | PASS |
| 3 | Real HTTP pipeline (request, privacy/bot checks, recorder, MySQL, rollup, dashboard) | PASS on disposable server |
| 4 | One request = one row; hash rules; bots; DNT/GPC; no IP/UA; UTC; click without `tool_votes` | PASS on disposable server |
| 5 | Staging code updated to the new commit | PENDING (VPS administrator) |
| 6 | Staging config repaired, web worker reads it (step 4 all PASS) | PENDING |
| 7 | `page_views` receives real staging visits (step 5) | PENDING |
| 8 | Dashboard on staging shows STAGING banner and recorded numbers | PENDING |
| 9 | Production untouched, no merge, no deployment, no secrets in Git/logs/reports | PASS |
**Overall: NOT YET SUCCESS on the live staging server.** Definition of done requires items 5 to 8.
