-- Phase 4 (P2): lead qualification, attribution and status tracking. ADDITIVE ONLY and reversible (see docs/phase4/ROLLBACK_PLAN.md).
-- Run once, by the site owner, in a migration session on a copy first. The application detects missing columns and keeps working
-- without them (extra qualification answers are then appended to the message), so deploying code before the migration is safe.
ALTER TABLE leads
    ADD COLUMN package_interest VARCHAR(32) NOT NULL DEFAULT '',
    ADD COLUMN campaign_goal VARCHAR(24) NOT NULL DEFAULT '',
    ADD COLUMN attr_source VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN attr_medium VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN attr_campaign VARCHAR(60) NOT NULL DEFAULT '',
    ADD COLUMN ref_tool VARCHAR(80) NOT NULL DEFAULT '',
    ADD COLUMN status_changed_at DATETIME NULL,
    ADD COLUMN is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN notify_status VARCHAR(12) NOT NULL DEFAULT '',
    ADD COLUMN notify_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN notify_error VARCHAR(24) NOT NULL DEFAULT '',
    ADD COLUMN notified_at DATETIME NULL,
    ADD KEY idx_leads_status (status, created_at);

CREATE TABLE IF NOT EXISTS lead_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id BIGINT UNSIGNED NOT NULL,
    old_status VARCHAR(20) NOT NULL DEFAULT '',
    new_status VARCHAR(20) NOT NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lsh_lead (lead_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
