-- Analytics rollback, part 2 (ONLY while page_views and events hold no data you want to keep): removes the two raw tracking tables created by this install.
-- STOP and check first:   SELECT (SELECT COUNT(*) FROM page_views) AS pv, (SELECT COUNT(*) FROM events) AS ev;   -- if either is > 0 and you want that data, take a backup before continuing.
-- Never touches tool_clicks (the 71,502 historical click records are not part of this install and are never dropped, truncated or altered by any file in database/analytics_install/).
DROP TABLE IF EXISTS events, page_views;
