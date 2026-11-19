-- Phase 8B rollback: removes ONLY the five rollup tables created by phase8b_analytics.sql. Raw tracking tables are untouched.
-- The rollups can always be rebuilt from page_views / events with: php scripts/adm_rollup.php --since-first
DROP TABLE IF EXISTS adm_rollup_run, adm_event_daily, adm_tool_daily, adm_traffic_dim_daily, adm_traffic_daily;
