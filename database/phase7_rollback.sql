-- Phase 7 rollback (MANUAL, DESTRUCTIVE). Removes ONLY the uni_* tables created by database/phase7_universe.sql, children first.
-- Triggers from phase7_triggers.sql are dropped together with their tables. crm_*, tools and every earlier table are not touched.
-- Idempotent: safe to run twice. Export the uni_* tables first if they hold real records.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS uni_audit_log, uni_correction, uni_release_parent, uni_release, uni_edge_source, uni_edge,
  uni_event_source, uni_event, uni_entity_source, uni_entity, uni_review, uni_source;
SET FOREIGN_KEY_CHECKS = 1;
