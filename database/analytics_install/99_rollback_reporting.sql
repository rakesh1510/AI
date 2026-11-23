-- Analytics rollback, part 1 (SAFE): drops ONLY the five derived reporting tables. They hold counts only and are rebuilt from raw rows with scripts/adm_rollup.php --since-first.
-- Never touches tool_clicks, page_views or events.
DROP TABLE IF EXISTS adm_rollup_run, adm_event_daily, adm_tool_daily, adm_traffic_dim_daily, adm_traffic_daily;
