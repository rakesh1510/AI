-- Rollback of analytics_a4_device.sql: removes only the device column. Device rollup rows (dim = 'device') in adm_traffic_dim_daily can be deleted with:
--   DELETE FROM adm_traffic_dim_daily WHERE dim = 'device';
ALTER TABLE page_views DROP COLUMN device;
