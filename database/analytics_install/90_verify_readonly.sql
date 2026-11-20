-- Analytics install: READ-ONLY verification after the migrations. SELECT only.
SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run') ORDER BY table_name;   -- expect 7 rows
SELECT COUNT(*) AS seven_tables_present FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run');   -- expect 7
SET @preflight_max_id = 0;   -- REPLACE 0 with the max_id printed by the preflight
SELECT COUNT(*) AS n, MAX(id) AS max_id, SUM(CRC32(CONCAT_WS('|', id, tool_slug, visitor_id, ip_hash, created_at))) AS fingerprint FROM tool_clicks WHERE id <= @preflight_max_id;   -- n and fingerprint must equal the preflight values
SELECT (SELECT COUNT(*) FROM page_views) AS page_views_rows, (SELECT COUNT(*) FROM events) AS events_rows;   -- 0 until tracking is enabled
