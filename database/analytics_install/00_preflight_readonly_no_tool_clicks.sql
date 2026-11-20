-- Analytics install: READ-ONLY preflight for a database WITHOUT `tool_clicks` (for example staging, which has no historical click data). SELECT and SHOW only.
SELECT DATABASE() AS database_name, VERSION() AS version, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, NOW() AS db_now, UTC_TIMESTAMP() AS utc_now, @@character_set_database AS charset, @@collation_database AS collation, @@sql_mode AS sql_mode;
SELECT table_name, table_rows AS approx_rows, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tool_clicks','tool_votes','page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run','adm_tool_feature') ORDER BY table_name;
SHOW GRANTS FOR CURRENT_USER();
