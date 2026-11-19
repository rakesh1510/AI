-- Phase 7 facts rollback (MANUAL, DESTRUCTIVE). Removes ONLY the tables created by database/phase7_facts.sql, children first.
-- Triggers from phase7_facts_triggers.sql are dropped together with their tables, EXCEPT trg_uni_fact_srcguard_bu which lives on uni_source (dropped explicitly below).
-- uni_universe tables (phase7_universe.sql), crm_* and every earlier table are not touched. Idempotent: safe to run twice. Export the fact tables first if they hold real records.
DROP TRIGGER IF EXISTS trg_uni_fact_srcguard_bu;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS uni_fact_source, uni_fact, uni_fact_review;
SET FOREIGN_KEY_CHECKS = 1;
