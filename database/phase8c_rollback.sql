-- Phase 8C rollback: removes ONLY the three tables created by phase8c_tools.sql. Tool rows and tools.status values are not touched.
DROP TABLE IF EXISTS adm_submission_review, adm_tool_feature, adm_tool_revision;
