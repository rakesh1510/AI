-- Phase 6 rollback (MANUAL, DESTRUCTIVE). Removes ONLY the tables created by database/phase6_crm.sql, children first.
-- It does not touch tools, categories, leads, subscribers, events or any earlier table. Export the crm_* tables first if they hold real records.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS crm_audit_log, crm_outreach_drafts, crm_prospects, crm_partners, crm_campaigns, crm_revenue_entries, crm_contracts,
  crm_proposal_items, crm_proposal_versions, crm_proposals, crm_tasks, crm_activities, crm_stage_history, crm_opportunities,
  crm_leads, crm_contacts, crm_companies, crm_users;
SET FOREIGN_KEY_CHECKS = 1;
