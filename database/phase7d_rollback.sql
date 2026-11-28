-- Phase 7D rollback: removes only the acquisition tables (Phase 6 crm_prospects, outreach drafts and contacts are untouched; status history, profiles and the suppression list are lost).
DROP TABLE IF EXISTS crm_prospect_suppressions;
DROP TABLE IF EXISTS crm_prospect_status_history;
DROP TABLE IF EXISTS crm_prospect_profile;
