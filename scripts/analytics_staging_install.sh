#!/usr/bin/env bash
# Analytics STAGING install (runbook steps 1-6) for database aihub_phase6_test ONLY. Run by an administrator; the application never runs it.
# The target database name is hard-coded: this script cannot be pointed at production (`aihub`) or anything else.
#
#   STAGING_DB_HOST=host STAGING_DB_PORT=3306 STAGING_DB_USER=admin MYSQL_PWD=... scripts/analytics_staging_install.sh            # preflight + backup + verification of the backup, then STOPS (no change)
#   ... scripts/analytics_staging_install.sh --restore-test                                                                          # also restores the tool_clicks backup into a throw-away scratch database it creates and drops
#   ... CONFIRM_INSTALL=aihub_phase6_test scripts/analytics_staging_install.sh --apply                                              # after a clean preflight and backup: installs the 7 tables, verifies
# Never: touches `aihub`, enables tracking, installs cron, deploys code, cleans identifiers, or drops/truncates/updates anything. It stops at the first unexpected finding (exit 2 = refused/stopped, 1 = failed after start, 0 = ok).
set -euo pipefail
DB=aihub_phase6_test; PROD=aihub
H=${STAGING_DB_HOST:?set STAGING_DB_HOST}; P=${STAGING_DB_PORT:-3306}; U=${STAGING_DB_USER:?set STAGING_DB_USER}
: "${MYSQL_PWD:?set MYSQL_PWD (the password is read from the environment, never from the command line)}"; export MYSQL_PWD
APPLY=0; RESTORE=0; for a in "$@"; do case "$a" in --apply) APPLY=1;; --restore-test) RESTORE=1;; *) echo "unknown option $a" >&2; exit 2;; esac; done
ROOT=$(cd "$(dirname "$0")/.." && pwd); TS=$(date -u +%Y%m%dT%H%M%SZ); OUT=${OUT_DIR:-$ROOT/analytics-staging-$TS}; mkdir -p "$OUT"; LOG=$OUT/run.log
say() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" | tee -a "$LOG"; }
stop() { say "STOP: $*"; say "Nothing was changed after this point. Report this line to the owner."; exit 2; }
m()  { mysql -h"$H" -P"$P" -u"$U" --batch --skip-column-names "$@"; }
md() { mysql -h"$H" -P"$P" -u"$U" --batch "$@"; }
say "Analytics staging install for $DB (apply=$APPLY restore_test=$RESTORE) output: $OUT"

# ---------- 1. connection, version, isolation
cur=$(m -e 'SELECT DATABASE()' "$DB" 2>>"$LOG") || stop "cannot connect to $DB on $H:$P as $U"
[ "$cur" = "$DB" ] || stop "connected database is '$cur', expected $DB"
read -r VER SRVHOST SRVPORT < <(m -e 'SELECT VERSION(), @@hostname, @@port' "$DB"); say "server: version=$VER hostname=$SRVHOST port=$SRVPORT user=$U database=$DB"
case "$VER" in *MariaDB*) say "NOTE: MariaDB, not MySQL; the owner expects MySQL 8.4.x on staging/production. Verify and report.";; esac
major=${VER%%.*}; [ "$major" -ge 8 ] 2>/dev/null || [ "${VER#*MariaDB}" != "$VER" ] || stop "server version $VER is below 8.0 (window functions required)"
case "$VER" in 8.4.*) say "version family 8.4: matches the expected production family";; 8.*) say "WARN: version $VER differs from production 8.4.x";; esac
if m -e "SHOW DATABASES LIKE '$PROD'" "$DB" | grep -qx "$PROD"; then
  say "WARN: database '$PROD' is VISIBLE on this server: staging and production share a server."
  [ "${ALLOW_SHARED_SERVER:-}" = "yes" ] || stop "isolation not proven: '$PROD' exists on the same server. Re-run with ALLOW_SHARED_SERVER=yes only after the owner accepts database-level isolation."
fi
m -e 'SHOW GRANTS FOR CURRENT_USER()' "$DB" > "$OUT/grants.txt" 2>>"$LOG" || stop "cannot read grants"
python3 - "$OUT/grants.txt" "$PROD" "$DB" <<'PY' || stop "this account has privileges that reach production database '$PROD' (see $OUT/grants.txt). Use a staging-only account."
import re,sys
prod, db = sys.argv[2], sys.argv[3]; bad=[]
for line in open(sys.argv[1]):
    mt = re.search(r"GRANT (.*?) ON (\*\.\*|`?([^`.]+)`?\.[^ ]+) TO", line)
    if not mt: continue
    privs, scope, pat = mt.group(1), mt.group(2), mt.group(3)
    if scope == '*.*':
        if re.fullmatch(r"USAGE|PROXY", privs.strip()): continue
        bad.append(line.strip()); continue
    rx = re.escape(pat.replace('\\_', '\0')).replace('%', '.*').replace('_', '.').replace('\0', '_') if False else '^' + re.sub(r'\\_', '_', pat).replace('%','.*').replace('_','.') + '$'
    if re.match(rx, prod): bad.append(line.strip())
if bad:
    print("\n".join(bad)); sys.exit(1)
PY
say "isolation: connected only to $DB; account has no privilege reaching '$PROD'; grants saved in grants.txt"
m -e 'SELECT @@global.time_zone, @@session.time_zone, NOW(), UTC_TIMESTAMP(), @@character_set_database, @@collation_database' "$DB" | tee -a "$LOG"

# ---------- 2. read-only preflight and schema compatibility
md "$DB" < "$ROOT/database/analytics_install/00_preflight_readonly.sql" > "$OUT/preflight.txt" 2>>"$LOG" || stop "read-only preflight failed (see run.log)"
say "preflight saved: preflight.txt"
cols=$(m -e "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$DB' AND table_name='tool_clicks'" "$DB")
[ -n "$cols" ] && [ "$cols" != "NULL" ] || stop "table tool_clicks does not exist in $DB"
[ "$cols" = "id,tool_slug,visitor_id,ip_hash,created_at" ] || stop "unexpected tool_clicks columns: $cols"
uniq=$(m -e "SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema='$DB' AND table_name='tool_clicks' AND non_unique=0 AND index_name<>'PRIMARY' GROUP BY index_name HAVING SUM(column_name IN ('visitor_id','ip_hash'))>0) t" "$DB")
[ "$uniq" = "0" ] || stop "tool_clicks has a UNIQUE index involving visitor_id or ip_hash: anonymous clicks would fail. An approved migration must fix this first."
say "tool_clicks: columns $cols; no unique index involving identifiers"
PRE=$(m -e "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(CRC32(CONCAT_WS('|',id,tool_slug,visitor_id,ip_hash,created_at))),0) FROM tool_clicks" "$DB"); read -r N MAXID FP <<<"$PRE"; say "tool_clicks fingerprint: n=$N max_id=$MAXID crc_sum=$FP"
echo "$N,$MAXID,$FP" > "$OUT/fingerprint.txt"
m -e "SELECT MIN(created_at), MAX(created_at), SUM(visitor_id<>''), SUM(ip_hash<>'') FROM tool_clicks" "$DB" | tee -a "$LOG"
SEVEN="page_views events adm_traffic_daily adm_traffic_dim_daily adm_tool_daily adm_event_daily adm_rollup_run"; existing=""; for t in $SEVEN; do c=$(m -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='$t'" "$DB"); [ "$c" = "1" ] && existing="$existing $t"; done
say "existing target tables:${existing:- none} (the migrations are IF NOT EXISTS: existing ones are left untouched)"
for t in leads subscribers; do c=$(m -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='$t'" "$DB"); say "other table $t: $([ "$c" = 1 ] && echo present || echo absent) (not touched by this install)"; done
if [ -x "$(command -v php)" ] && [ -f "$ROOT/scripts/analytics_verify.php" ]; then
  AIHUB_DB_HOST="$H:$P" AIHUB_DB_NAME="$DB" AIHUB_DB_USER="$U" AIHUB_DB_PASS="$MYSQL_PWD" php "$ROOT/scripts/analytics_verify.php" --phase=pre --expect-db="$DB" > "$OUT/verify-pre.txt" 2>&1 || { cat "$OUT/verify-pre.txt" | tail -15; stop "verify --phase=pre reported FAIL (see verify-pre.txt)"; }
  say "verify --phase=pre: no FAIL ($(grep -c '^WARN' "$OUT/verify-pre.txt") warnings; see verify-pre.txt)"
else say "php not available here: run 'php scripts/analytics_verify.php --phase=pre' on the application host"; fi

# ---------- 3. backup and verification
mysqldump -h"$H" -P"$P" -u"$U" --single-transaction --no-tablespaces --routines --triggers "$DB" 2>>"$LOG" | gzip > "$OUT/$DB-full.sql.gz" || stop "full backup failed"
mysqldump -h"$H" -P"$P" -u"$U" --single-transaction --no-tablespaces "$DB" tool_clicks 2>>"$LOG" | gzip > "$OUT/tool_clicks.sql.gz" || stop "tool_clicks backup failed"
( cd "$OUT" && sha256sum "$DB-full.sql.gz" tool_clicks.sql.gz > backups.sha256 )
for f in "$OUT/$DB-full.sql.gz" "$OUT/tool_clicks.sql.gz"; do gzip -t "$f" || stop "backup file is corrupt: $f"; [ "$(stat -c %s "$f")" -gt 500 ] || stop "backup file is suspiciously small: $f"; zcat "$f" | tail -3 | grep -q "Dump completed" || stop "backup is incomplete (no 'Dump completed' marker): $f"; done
if [ "$N" -gt 0 ]; then ins=$(zcat "$OUT/tool_clicks.sql.gz" | grep -c 'INSERT INTO `tool_clicks`' || true); [ "${ins:-0}" -gt 0 ] || stop "tool_clicks backup contains no rows although the table has $N"; fi
say "backups written and integrity-checked: $(ls -l "$OUT"/*.sql.gz | awk '{print $5" bytes "$9}' | tr '\n' ';')"
if [ "$RESTORE" = 1 ]; then
  SCR=${DB}_bkverify_$TS; say "restore test: creating throw-away scratch database $SCR (needs CREATE privilege)"; m -e "CREATE DATABASE \`$SCR\`" || stop "cannot create the scratch database (no CREATE privilege): re-run without --restore-test and verify the restore elsewhere"
  zcat "$OUT/tool_clicks.sql.gz" | mysql -h"$H" -P"$P" -u"$U" "$SCR" || { m -e "DROP DATABASE \`$SCR\`" || true; stop "restore into scratch failed"; }
  R=$(m -e "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(CRC32(CONCAT_WS('|',id,tool_slug,visitor_id,ip_hash,created_at))),0) FROM tool_clicks" "$SCR"); m -e "DROP DATABASE \`$SCR\`"
  [ "$R" = "$PRE" ] || stop "restore test mismatch: backup restores to '$R' but the live table is '$PRE'"
  say "restore test passed: the backup restores to an identical tool_clicks (n, max_id, fingerprint); scratch database dropped"
fi
if [ "$APPLY" != 1 ]; then say "DRY RUN COMPLETE: preflight and backup passed. No change was made. Re-run with --apply and CONFIRM_INSTALL=$DB after the owner confirms."; exit 0; fi
[ "${CONFIRM_INSTALL:-}" = "$DB" ] || stop "--apply needs CONFIRM_INSTALL=$DB"

# ---------- 4. install (additive, idempotent) and 5. post-verification
for f in database/analytics_install/01_page_views.sql database/analytics_install/02_events.sql database/phase8b_analytics.sql; do say "applying $f"; md "$DB" < "$ROOT/$f" >> "$LOG" 2>&1 || { say "FAILED while applying $f"; exit 1; }; done
c=$(m -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name IN ('page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run')" "$DB"); [ "$c" = "7" ] || { say "FAIL: $c of 7 tables present"; exit 1; }
say "installed tables: $(m -e "SELECT GROUP_CONCAT(table_name ORDER BY table_name) FROM information_schema.tables WHERE table_schema='$DB' AND table_name IN ('page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run')" "$DB")"
POST=$(m -e "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|',id,tool_slug,visitor_id,ip_hash,created_at))),0) FROM tool_clicks WHERE id <= $MAXID" "$DB"); [ "$POST" = "$N	$FP" ] || { say "FAIL: historical tool_clicks changed! before n=$N fp=$FP, after '$POST'. STOP. Restore from $OUT/tool_clicks.sql.gz only after review."; exit 1; }
say "tool_clicks historical records identical after install (n=$N, crc_sum=$FP)"
md "$DB" < "$ROOT/database/analytics_install/90_verify_readonly.sql" > "$OUT/verify-sql.txt" 2>>"$LOG" || { say "FAIL: 90_verify_readonly.sql errored"; exit 1; }
for t in page_views events; do say "$t rows: $(m -e "SELECT COUNT(*) FROM $t" "$DB") (expected 0: tracking is off)"; done
if command -v php >/dev/null 2>&1; then AIHUB_DB_HOST="$H:$P" AIHUB_DB_NAME="$DB" AIHUB_DB_USER="$U" AIHUB_DB_PASS="$MYSQL_PWD" php "$ROOT/scripts/analytics_verify.php" --phase=post --expect-db="$DB" --fingerprint="$N,$MAXID,$FP" > "$OUT/verify-post.txt" 2>&1 && say "verify --phase=post: no FAIL" || { tail -12 "$OUT/verify-post.txt"; say "FAIL: verify --phase=post reported FAIL"; exit 1; }; fi
say "step 6 UTC (read-only): PHP $(php -r 'echo date_default_timezone_get(), " local=", date("c"), " utc=", gmdate("c");' 2>/dev/null || echo n/a) | DB $(m -e 'SELECT @@global.time_zone, @@session.time_zone, NOW(), UTC_TIMESTAMP()' "$DB")"
say "DONE. Tracking is still OFF, no cron, no cleanup. Open /admin-center.php?m=analytics as owner on staging: it should say 'No tracking data yet ... not enabled'. Evidence folder: $OUT"
