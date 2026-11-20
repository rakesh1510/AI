#!/usr/bin/env bash
# Analytics STAGING install (runbook steps 1-6) for database aihub_phase6_test ONLY. Run by an administrator; the application never runs it.
# The target database name is hard-coded: this script cannot be pointed at production (`aihub`) or anything else.
#
#   STAGING_DB_HOST=host STAGING_DB_PORT=3306 STAGING_DB_USER=admin MYSQL_PWD=... scripts/analytics_staging_install.sh            # preflight + backup + verification of the backup, then STOPS (no change)
#   ... scripts/analytics_staging_install.sh --restore-test                                                                          # also restores the tool_clicks backup into a throw-away scratch database it creates and drops
#   ... CONFIRM_INSTALL=aihub_phase6_test scripts/analytics_staging_install.sh --apply                                              # after a clean preflight and backup: installs the 7 tables, verifies
#   ... CONFIRM_INSTALL=aihub_phase6_test scripts/analytics_staging_install.sh --apply --create-tool-votes                             # optional: also create an EMPTY tool_votes table if missing (lets the app write tool_clicks rows on staging)
# If tool_clicks does not exist (staging has no historical clicks) --apply creates it EMPTY with the five production columns; nothing is ever copied from production.
# Never: touches `aihub`, enables tracking, installs cron, deploys code, cleans identifiers, or drops/truncates/updates anything. It stops at the first unexpected finding (exit 2 = refused/stopped, 1 = failed after start, 0 = ok).
set -euo pipefail
DB=aihub_phase6_test; PROD=aihub
H=${STAGING_DB_HOST:?set STAGING_DB_HOST}; P=${STAGING_DB_PORT:-3306}; U=${STAGING_DB_USER:?set STAGING_DB_USER}
: "${MYSQL_PWD:?set MYSQL_PWD (the password is read from the environment, never from the command line)}"; export MYSQL_PWD
APPLY=0; RESTORE=0; VOTES=0; for a in "$@"; do case "$a" in --apply) APPLY=1;; --restore-test) RESTORE=1;; --create-tool-votes) VOTES=1;; *) echo "unknown option $a" >&2; exit 2;; esac; done
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
tcount() { m -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='$1'" "$DB"; }
TC=$(tcount tool_clicks)
if [ "$TC" = "1" ]; then
  cols=$(m -e "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$DB' AND table_name='tool_clicks'" "$DB")
  [ "$cols" = "id,tool_slug,visitor_id,ip_hash,created_at" ] || stop "unexpected tool_clicks columns: $cols (an existing table is never altered by this installer)"
  uniq=$(m -e "SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema='$DB' AND table_name='tool_clicks' AND non_unique=0 AND index_name<>'PRIMARY' GROUP BY index_name HAVING SUM(column_name IN ('visitor_id','ip_hash'))>0) t" "$DB")
  [ "$uniq" = "0" ] || stop "tool_clicks has a UNIQUE index involving visitor_id or ip_hash: anonymous clicks would fail. An approved migration must fix this first."
  md "$DB" < "$ROOT/database/analytics_install/00_preflight_readonly.sql" > "$OUT/preflight.txt" 2>>"$LOG" || stop "read-only preflight failed (see run.log)"
  say "tool_clicks EXISTS: columns $cols; no unique index involving identifiers"
  PRE=$(m -e "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(CRC32(CONCAT_WS('|',id,tool_slug,visitor_id,ip_hash,created_at))),0) FROM tool_clicks" "$DB"); read -r N MAXID FP <<<"$PRE"
  m -e "SELECT MIN(created_at), MAX(created_at), SUM(COALESCE(visitor_id,'')<>''), SUM(COALESCE(ip_hash,'')<>'') FROM tool_clicks" "$DB" | tee -a "$LOG"
else
  md "$DB" < "$ROOT/database/analytics_install/00_preflight_readonly_no_tool_clicks.sql" > "$OUT/preflight.txt" 2>>"$LOG" || stop "read-only preflight failed (see run.log)"
  say "tool_clicks does NOT exist in $DB (no historical click data on staging). --apply will create it EMPTY with the five production columns (03_tool_clicks_if_missing.sql). Nothing is copied from production."
  N=0; MAXID=0; FP=0
fi
say "preflight saved: preflight.txt"; say "tool_clicks fingerprint: n=$N max_id=$MAXID crc_sum=$FP"; echo "$N,$MAXID,$FP" > "$OUT/fingerprint.txt"
SEVEN="page_views events adm_traffic_daily adm_traffic_dim_daily adm_tool_daily adm_event_daily adm_rollup_run"; existing=""; for t in $SEVEN; do c=$(tcount "$t"); [ "$c" = "1" ] && existing="$existing $t"; done
say "existing target tables:${existing:- none} (the migrations are IF NOT EXISTS: existing ones are left untouched)"
for t in leads subscribers tool_votes; do c=$(tcount "$t"); say "other table $t: $([ "$c" = 1 ] && echo present || echo absent) (not touched by this install unless --create-tool-votes)"; done
[ "$(tcount tool_votes)" = "1" ] || say "NOTE: tool_votes is absent: the application then writes no tool_clicks rows (outbound clicks are still counted in events). Use --create-tool-votes if you want the full click path tested."
# every existing table's row count: proof that CRM, AI Universe and all other records are untouched
m -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE' ORDER BY table_name" "$DB" > "$OUT/tables-before.txt"
: > "$OUT/counts-before.txt"; while read -r t; do printf '%s\t%s\n' "$t" "$(m -e "SELECT COUNT(*) FROM \`$t\`" "$DB")" >> "$OUT/counts-before.txt"; done < "$OUT/tables-before.txt"
say "existing tables: $(wc -l < "$OUT/tables-before.txt"), row counts saved in counts-before.txt (total rows $(awk '{s+=$2} END{print s+0}' "$OUT/counts-before.txt"))"
if command -v php >/dev/null 2>&1 && [ -f "$ROOT/scripts/analytics_verify.php" ]; then
  FLAG=""; [ "$TC" = "1" ] || FLAG="--allow-missing-tool-clicks"
  AIHUB_DB_HOST="$H:$P" AIHUB_DB_NAME="$DB" AIHUB_DB_USER="$U" AIHUB_DB_PASS="$MYSQL_PWD" php "$ROOT/scripts/analytics_verify.php" --phase=pre --expect-db="$DB" $FLAG > "$OUT/verify-pre.txt" 2>&1 || { tail -15 "$OUT/verify-pre.txt"; stop "verify --phase=pre reported FAIL (see verify-pre.txt)"; }
  say "verify --phase=pre: no FAIL ($(grep -c '^WARN' "$OUT/verify-pre.txt") warnings; see verify-pre.txt)"
else say "php not available here: run 'php scripts/analytics_verify.php --phase=pre$([ "$TC" = 1 ] || echo ' --allow-missing-tool-clicks')' on the application host"; fi

# ---------- 3. backup and verification
DUMPOPT="--single-transaction --no-tablespaces"; mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged' && DUMPOPT="$DUMPOPT --set-gtid-purged=OFF"   # MySQL 8.4: no GTID_PURGED statements that a restore account could not run
FULL="$OUT/$DB-full.sql.gz"
if ! mysqldump -h"$H" -P"$P" -u"$U" $DUMPOPT --routines --triggers "$DB" 2>>"$LOG" | gzip > "$FULL"; then
  say "WARN: dump with --routines/--triggers failed (missing SHOW_ROUTINE/TRIGGER privilege?); retrying without them"
  mysqldump -h"$H" -P"$P" -u"$U" $DUMPOPT "$DB" 2>>"$LOG" | gzip > "$FULL" || stop "full backup failed"
fi
FILES="$FULL"
if [ "$TC" = "1" ]; then mysqldump -h"$H" -P"$P" -u"$U" $DUMPOPT "$DB" tool_clicks 2>>"$LOG" | gzip > "$OUT/tool_clicks.sql.gz" || stop "tool_clicks backup failed"; FILES="$FILES $OUT/tool_clicks.sql.gz"; fi
( cd "$OUT" && sha256sum $(for f in $FILES; do basename "$f"; done) > backups.sha256 )
for f in $FILES; do gzip -t "$f" || stop "backup file is corrupt: $f"; [ "$(stat -c %s "$f")" -gt 500 ] || stop "backup file is suspiciously small: $f"; zcat "$f" | tail -3 | grep -q "Dump completed" || stop "backup is incomplete (no 'Dump completed' marker): $f"; done
if [ "$N" -gt 0 ]; then ins=$(zcat "$OUT/tool_clicks.sql.gz" | grep -c 'INSERT INTO `tool_clicks`' || true); [ "${ins:-0}" -gt 0 ] || stop "tool_clicks backup contains no rows although the table has $N"; fi
say "backups written and integrity-checked: $(ls -l $FILES | awk '{print $5" bytes "$9}' | tr '\n' ';')"
if [ "$RESTORE" = 1 ]; then
  SCR=${DB}_bkverify_$TS; say "restore test: creating throw-away scratch database $SCR (needs CREATE privilege) and restoring the FULL backup into it"; m -e "CREATE DATABASE \`$SCR\`" || stop "cannot create the scratch database (no CREATE privilege): re-run without --restore-test and verify the restore elsewhere"
  zcat "$FULL" | mysql -h"$H" -P"$P" -u"$U" "$SCR" || { m -e "DROP DATABASE \`$SCR\`" || true; stop "restore of the full backup into the scratch database failed"; }
  : > "$OUT/counts-restored.txt"; while read -r t; do printf '%s\t%s\n' "$t" "$(m -e "SELECT COUNT(*) FROM \`$t\`" "$SCR")" >> "$OUT/counts-restored.txt"; done < "$OUT/tables-before.txt"
  if [ "$TC" = "1" ]; then R=$(m -e "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(CRC32(CONCAT_WS('|',id,tool_slug,visitor_id,ip_hash,created_at))),0) FROM tool_clicks" "$SCR"); [ "$R" = "$PRE" ] || { m -e "DROP DATABASE \`$SCR\`" || true; stop "restore test mismatch for tool_clicks: '$R' versus live '$PRE'"; }; fi
  m -e "DROP DATABASE \`$SCR\`"
  cmp -s "$OUT/counts-before.txt" "$OUT/counts-restored.txt" || stop "restore test mismatch: row counts of the restored copy differ from the live database (see counts-before.txt and counts-restored.txt)"
  say "restore test passed: every table restores with identical row counts$([ "$TC" = 1 ] && echo ' and an identical tool_clicks fingerprint'); scratch database dropped"
fi
if [ "$APPLY" != 1 ]; then say "DRY RUN COMPLETE: preflight and backup passed. No change was made. Re-run with --apply and CONFIRM_INSTALL=$DB after the owner confirms."; exit 0; fi
[ "${CONFIRM_INSTALL:-}" = "$DB" ] || stop "--apply needs CONFIRM_INSTALL=$DB"

# ---------- 4. install (additive, idempotent) and 5. post-verification
APPLYFILES=""; [ "$TC" = "1" ] || APPLYFILES="database/analytics_install/03_tool_clicks_if_missing.sql"; [ "$VOTES" = 1 ] && APPLYFILES="$APPLYFILES database/analytics_install/04_tool_votes_if_missing_OPTIONAL.sql"
APPLYFILES="$APPLYFILES database/analytics_install/01_page_views.sql database/analytics_install/02_events.sql database/phase8b_analytics.sql"
for f in $APPLYFILES; do say "applying $f"; md "$DB" < "$ROOT/$f" >> "$LOG" 2>&1 || { say "FAILED while applying $f"; exit 1; }; done
c=$(m -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name IN ('page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run')" "$DB"); [ "$c" = "7" ] || { say "FAIL: $c of 7 tables present"; exit 1; }
say "installed tables: $(m -e "SELECT GROUP_CONCAT(table_name ORDER BY table_name) FROM information_schema.tables WHERE table_schema='$DB' AND table_name IN ('page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run')" "$DB")"
cols=$(m -e "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$DB' AND table_name='tool_clicks'" "$DB"); [ "$cols" = "id,tool_slug,visitor_id,ip_hash,created_at" ] || { say "FAIL: tool_clicks columns are '$cols'"; exit 1; }
if [ "$TC" = "1" ]; then say "tool_clicks verified: the five-column table already existed and was not altered"; else say "tool_clicks created EMPTY with columns $cols (rows: $(m -e 'SELECT COUNT(*) FROM tool_clicks' "$DB"))"; [ "$(m -e 'SELECT COUNT(*) FROM tool_clicks' "$DB")" = "0" ] || { say "FAIL: the newly created tool_clicks is not empty"; exit 1; }; fi
POST=$(m -e "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|',id,tool_slug,visitor_id,ip_hash,created_at))),0) FROM tool_clicks WHERE id <= $MAXID" "$DB"); [ "$POST" = "$N	$FP" ] || { say "FAIL: historical tool_clicks changed! before n=$N fp=$FP, after '$POST'. STOP. Restore from $OUT/ only after review."; exit 1; }
say "historical tool_clicks records identical after install (n=$N, crc_sum=$FP)"
: > "$OUT/counts-after.txt"; while read -r t; do printf '%s\t%s\n' "$t" "$(m -e "SELECT COUNT(*) FROM \`$t\`" "$DB")" >> "$OUT/counts-after.txt"; done < "$OUT/tables-before.txt"
if [ "$TC" = "1" ]; then grep -v "^tool_clicks	" "$OUT/counts-before.txt" > "$OUT/.b"; grep -v "^tool_clicks	" "$OUT/counts-after.txt" > "$OUT/.a"; else cp "$OUT/counts-before.txt" "$OUT/.b"; cp "$OUT/counts-after.txt" "$OUT/.a"; fi
cmp -s "$OUT/.b" "$OUT/.a" || { say "FAIL: row counts of pre-existing tables changed (see counts-before.txt and counts-after.txt)"; exit 1; }; rm -f "$OUT/.a" "$OUT/.b"
say "all $(wc -l < "$OUT/tables-before.txt") pre-existing tables have identical row counts after the install (CRM, AI Universe and every other record untouched)"
sed "s/SET @preflight_max_id = 0;/SET @preflight_max_id = $MAXID;/" "$ROOT/database/analytics_install/90_verify_readonly.sql" | md "$DB" > "$OUT/verify-sql.txt" 2>>"$LOG" || { say "FAIL: 90_verify_readonly.sql errored"; exit 1; }
for t in page_views events; do say "$t rows: $(m -e "SELECT COUNT(*) FROM $t" "$DB") (expected 0: tracking is off)"; done
if command -v php >/dev/null 2>&1; then AIHUB_DB_HOST="$H:$P" AIHUB_DB_NAME="$DB" AIHUB_DB_USER="$U" AIHUB_DB_PASS="$MYSQL_PWD" php "$ROOT/scripts/analytics_verify.php" --phase=post --expect-db="$DB" --fingerprint="$N,$MAXID,$FP" > "$OUT/verify-post.txt" 2>&1 && say "verify --phase=post: no FAIL" || { tail -12 "$OUT/verify-post.txt"; say "FAIL: verify --phase=post reported FAIL"; exit 1; }; fi
say "step 6 UTC (read-only): PHP $(php -r 'echo date_default_timezone_get(), " local=", date("c"), " utc=", gmdate("c");' 2>/dev/null || echo n/a) | DB $(m -e 'SELECT @@global.time_zone, @@session.time_zone, NOW(), UTC_TIMESTAMP()' "$DB")"
say "DONE. Tracking is still OFF, no cron, no cleanup. Open /admin-center.php?m=analytics as owner on staging: it should say 'No tracking data yet ... not enabled'. Evidence folder: $OUT"
