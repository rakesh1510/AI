-- READ-ONLY preflight for the staging database (SELECT and SHOW only). Run ONLY after the owner approves checkpoint A2, as a read-only account, on STAGING.
-- Nothing here writes, alters or reads personal data. Do not run on production.
SELECT VERSION() AS server_version, @@version_comment AS distribution, @@sql_mode AS sql_mode, @@character_set_database AS charset, @@collation_database AS collation;
SELECT DATABASE() AS current_database;                                                -- must be aihub_phase6_test on staging
SELECT table_name, engine, table_rows, table_collation FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name;
-- Which migration steps are already applied (a 1 means the table exists)
SELECT
  SUM(table_name = 'tools') AS tools, SUM(table_name = 'categories') AS categories, SUM(table_name = 'posts') AS posts, SUM(table_name = 'use_cases') AS use_cases, SUM(table_name = 'submissions') AS submissions,
  SUM(table_name = 'page_views') AS page_views, SUM(table_name = 'events') AS events, SUM(table_name = 'tool_clicks') AS tool_clicks,
  SUM(table_name = 'crm_contracts') AS crm_contracts, SUM(table_name = 'crm_audit_log') AS crm_audit_log, SUM(table_name = 'uni_entity') AS uni_entity,
  SUM(table_name = 'adm_traffic_daily') AS adm_traffic_daily, SUM(table_name = 'adm_tool_revision') AS adm_tool_revision, SUM(table_name = 'fin_expense_entry') AS fin_expense_entry
FROM information_schema.tables WHERE table_schema = DATABASE();
-- Content source: the site reads MySQL content only when all four of these tables exist (mysql_tables_available_for_content)
SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('categories','tools','posts','use_cases')) AS content_tables_present_of_4;
SELECT status, COUNT(*) AS n FROM tools GROUP BY status;                                -- only if tools exists: published versus other
SELECT COUNT(*) AS categories_rows FROM categories;                                      -- only if categories exists
-- Grants of the account in use (confirm it is the least-privilege account, not root)
SHOW GRANTS FOR CURRENT_USER();
-- Triggers (Phase 7 needs them; their absence changes the Phase 7 verification)
SELECT trigger_name, event_object_table FROM information_schema.triggers WHERE trigger_schema = DATABASE() ORDER BY trigger_name;
-- Existing-table fingerprint to compare after the migrations (record the output; do not edit)
-- CHECKSUM TABLE tools, categories, submissions, crm_contracts, crm_revenue_entries;
