-- Phase 7C rollback: removes only the quotation tables (quotations themselves stay in crm_proposals; milestones, decisions and numbering counters are lost).
DROP TABLE IF EXISTS crm_quote_counter;
DROP TABLE IF EXISTS crm_proposal_events;
DROP TABLE IF EXISTS crm_proposal_milestones;
DROP TABLE IF EXISTS crm_proposal_extra;
