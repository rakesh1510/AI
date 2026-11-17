#!/usr/bin/env bash
# Validates .htaccess behaviour on a real Apache 2.4 + mod_php, LOCALLY, with the isolated test database. Never run against a real site.
# Requires: apache2, libapache2-mod-php, the test database (tests/setup_test_db.sh), root or sudo (Apache needs www-data).
# Usage: tests/apache/run_apache_validation.sh [port]   then run: php tests/seo_test.php http://127.0.0.1:<port> --full-sitemap
set -euo pipefail
cd "$(dirname "$0")/../.."
PORT="${1:-8081}"; WORK="$(mktemp -d /tmp/aihub_apache.XXXXXX)"; DOCROOT="$(pwd)"
mkdir -p "$WORK/logs" "$WORK/run" /tmp/aihub_rate_apache /tmp/aihub_cache_apache
chmod 777 "$WORK/logs" /tmp/aihub_rate_apache; chown www-data:www-data /tmp/aihub_cache_apache; chmod 700 /tmp/aihub_cache_apache
chmod -R o+rX "$DOCROOT" 2>/dev/null || true; chmod o+w data data/*.json   # the comment/rating forms write JSON; run tests/restore_test_data.sh afterwards
sed -e "s#@WORK@#$WORK#g" -e "s#@PORT@#$PORT#g" -e "s#@DOCROOT@#$DOCROOT#g" tests/apache/apache-test.conf.template > "$WORK/apache2.conf"
HASH="$(php -r 'echo password_hash("ci-admin-test-pw", PASSWORD_DEFAULT);')"
echo "SetEnv AIHUB_ADMIN_PASS_HASH '$HASH'" >> "$WORK/apache2.conf"
echo "SetEnv AIHUB_CACHE_DIR /tmp/aihub_cache_apache" >> "$WORK/apache2.conf"
apache2 -t -f "$WORK/apache2.conf"
apache2 -f "$WORK/apache2.conf"
echo "Apache listening on 127.0.0.1:$PORT (config in $WORK). Stop with: kill \$(cat $WORK/run/apache2.pid)"
