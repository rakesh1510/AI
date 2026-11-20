-- Analytics install: READ-ONLY preflight. Contains only SELECT and SHOW statements. Safe on production. Save the output with the approval note.
SELECT DATABASE() AS database_name, VERSION() AS version, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, NOW() AS db_now, UTC_TIMESTAMP() AS utc_now, @@character_set_database AS charset, @@collation_database AS collation, @@sql_mode AS sql_mode;
SELECT table_name, table_rows AS approx_rows, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tool_clicks','tool_votes','page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run','adm_tool_feature') ORDER BY table_name;
SELECT column_name, column_type, is_nullable, column_default, extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tool_clicks' ORDER BY ordinal_position;
SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tool_clicks' GROUP BY index_name, non_unique;
SELECT COUNT(*) AS rows_total, MIN(id) AS min_id, MAX(id) AS max_id, MIN(created_at) AS first_click, MAX(created_at) AS last_click, SUM(visitor_id <> '') AS with_visitor_id, SUM(ip_hash <> '') AS with_ip_hash FROM tool_clicks;
-- Fingerprint of the historical click records. Run again after installation: all four numbers must be IDENTICAL (new clicks after the install will change them: compare using the same upper bound id).
SELECT COUNT(*) AS n, MAX(id) AS max_id, SUM(CRC32(CONCAT_WS('|', id, tool_slug, visitor_id, ip_hash, created_at))) AS fingerprint FROM tool_clicks;
SHOW GRANTS FOR CURRENT_USER();
