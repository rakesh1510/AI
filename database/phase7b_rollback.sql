-- Phase 7B rollback: removes ONLY the three Phase 7B tables. Export them first if they hold data you want to keep. No other table is touched.
DROP TABLE IF EXISTS crm_notification_log;
DROP TABLE IF EXISTS crm_consultations;
DROP TABLE IF EXISTS crm_lead_response;
