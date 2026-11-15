#!/usr/bin/env bash
# Builds an ISOLATED local test database from the repo schema + JSON seed data.
# Never point this at production. Requires a local MySQL/MariaDB server.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${AIHUB_DB_HOST:=127.0.0.1}" "${AIHUB_DB_NAME:=aihub_test}" "${AIHUB_DB_USER:=aihub_test}" "${AIHUB_DB_PASS:=test_only_pw}"
export AIHUB_DB_HOST AIHUB_DB_NAME AIHUB_DB_USER AIHUB_DB_PASS
case "$AIHUB_DB_NAME" in *test*) ;; *) echo "Refusing: DB name must contain 'test'"; exit 1;; esac
M="mysql -h$AIHUB_DB_HOST -u$AIHUB_DB_USER -p$AIHUB_DB_PASS"
$M -e "DROP DATABASE IF EXISTS \`$AIHUB_DB_NAME\`; CREATE DATABASE \`$AIHUB_DB_NAME\` CHARACTER SET utf8mb4;"
# core content tables (skip the outdated interaction table definitions in the schema file)
sed '/CREATE TABLE IF NOT EXISTS tool_clicks/,$d' database/full_mysql_schema.sql > /tmp/aihub_core_schema.sql
sed -n '/CREATE TABLE IF NOT EXISTS submissions/,$p' database/full_mysql_schema.sql >> /tmp/aihub_core_schema.sql
$M "$AIHUB_DB_NAME" < /tmp/aihub_core_schema.sql
$M "$AIHUB_DB_NAME" -e "ALTER TABLE use_cases ADD icon VARCHAR(20) NULL, ADD description TEXT NULL, ADD hero_text TEXT NULL, ADD audience TEXT NULL, ADD popular_tasks LONGTEXT NULL, ADD example_tools LONGTEXT NULL, ADD benefits LONGTEXT NULL;"
$M "$AIHUB_DB_NAME" < tests/fixtures/interactions_current.sql
$M "$AIHUB_DB_NAME" < database/install_compare_tables.sql
$M "$AIHUB_DB_NAME" < database/phase2_analytics.sql
$M "$AIHUB_DB_NAME" < database/phase3_business.sql
php import-json-to-mysql.php
# Replace any credentials copied from data/site.json with test-only values (local SMTP sink, dummy admin password).
$M "$AIHUB_DB_NAME" -e "UPDATE site_settings SET setting_value='{\"from_email\":\"noreply@example.test\",\"from_name\":\"AIHubpedia Test\",\"to_email\":\"inbox@example.test\",\"smtp_host\":\"127.0.0.1\",\"smtp_port\":2525,\"smtp_secure\":\"\",\"smtp_user\":\"test\",\"smtp_pass\":\"test\"}' WHERE setting_key='mail'; UPDATE site_settings SET setting_value='test-admin-pass' WHERE setting_key='admin_pass';"
# TEST-ONLY sponsored listings: the real catalogue has none, so seed two in the isolated test DB to exercise disclosure labels
$M "$AIHUB_DB_NAME" -e "UPDATE tools SET featured_level=1, featured_until=DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE slug IN ('otter-ai','sonix');"
# synthetic engagement data (no real users)
php -r '
$pdo=new PDO("mysql:host=".getenv("AIHUB_DB_HOST").";dbname=".getenv("AIHUB_DB_NAME"),getenv("AIHUB_DB_USER"),getenv("AIHUB_DB_PASS"));
$slugs=$pdo->query("SELECT slug FROM tools")->fetchAll(PDO::FETCH_COLUMN);
mt_srand(42);$c=$pdo->prepare("INSERT INTO tool_clicks(tool_slug,visitor_id,ip_hash) VALUES(?,?,?)");
$v=$pdo->prepare("INSERT IGNORE INTO tool_votes(tool_slug,visitor_id,vote_type,ip_hash) VALUES(?,?,?,?)");
$pdo->beginTransaction();
foreach($slugs as $s){for($i=0,$n=mt_rand(0,25);$i<$n;$i++)$c->execute([$s,md5("v$i"),hash("sha256","t$i")]);
 for($i=0,$n=mt_rand(0,8);$i<$n;$i++)$v->execute([$s,md5("u$i"),mt_rand(0,4)?"up":"down",hash("sha256","t$i")]);}
$pdo->commit();echo "Seeded synthetic clicks/votes for ".count($slugs)." tools\n";'
