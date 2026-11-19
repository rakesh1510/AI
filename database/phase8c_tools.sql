-- Phase 8C: AI tool management. ADDITIVE ONLY: three new tables, every statement CREATE TABLE IF NOT EXISTS.
-- No existing table is altered (tools, categories, submissions, crm_audit_log and every other table are untouched). Idempotent.
-- NOT EXECUTED by the development team: the owner's administrator runs it, after a backup, only with the owner's approval.
-- Lifecycle uses the existing tools.status column: draft | published | unpublished | deleted.
CREATE TABLE IF NOT EXISTS adm_tool_revision (      -- append-only history; powers diff and restore
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tool_slug VARCHAR(190) NOT NULL, revision_no INT NOT NULL,
  action VARCHAR(24) NOT NULL,                      -- create|edit|publish|unpublish|delete|restore|feature|import
  changed_by VARCHAR(40) NOT NULL, changed_at DATETIME NOT NULL,
  before_json LONGTEXT NULL, after_json LONGTEXT NULL, note VARCHAR(500) NULL,
  UNIQUE KEY uq_tool_rev (tool_slug, revision_no), KEY idx_rev_at (changed_at)
);
CREATE TABLE IF NOT EXISTS adm_tool_feature (       -- featured placement history and schedule
  id BIGINT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(190) NOT NULL,
  level TINYINT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manual',     -- manual|submission|campaign
  submission_id INT NULL, status VARCHAR(12) NOT NULL DEFAULT 'scheduled',  -- scheduled|active|ended|cancelled
  note VARCHAR(500) NULL, created_by VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL,
  KEY idx_feat_tool (tool_slug, status), KEY idx_feat_dates (starts_on, ends_on)
);
CREATE TABLE IF NOT EXISTS adm_submission_review (  -- reviewer decisions for public submissions
  id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, decision VARCHAR(12) NOT NULL,  -- approved|rejected|needs_info
  tool_slug VARCHAR(190) NULL, reviewed_by VARCHAR(40) NOT NULL, reviewed_at DATETIME NOT NULL, note VARCHAR(1000) NULL,
  UNIQUE KEY uq_sub_review (submission_id), KEY idx_sub_dec (decision)
);
