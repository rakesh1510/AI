-- Phase 7B: lead response management (additive, idempotent: CREATE TABLE IF NOT EXISTS only; no ALTER, no change to any existing table).
-- Apply to a REVIEWED database only, after database/phase6_crm.sql. Rollback: database/phase7b_rollback.sql (drops only these three tables).
-- No foreign keys on purpose (the CRM stores plain ids, so mixed collations in older databases cannot cause "illegal mix of collations").

-- One row per enquiry: response deadline, transparent priority score, the single follow-up task, and the first HUMAN response.
CREATE TABLE IF NOT EXISTS crm_lead_response (
    lead_id BIGINT UNSIGNED NOT NULL,
    due_at DATETIME NOT NULL,                        -- UTC; created + 4 business hours (Mon-Fri 09:00-17:00 Europe/Berlin, holidays excluded)
    priority_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    priority_band VARCHAR(8) NOT NULL DEFAULT 'low', -- high | medium | low
    priority_reasons TEXT NULL,                      -- JSON list of [reason, points]
    task_id BIGINT UNSIGNED NULL,                    -- the one auto-created follow-up task (crm_tasks.id)
    first_response_at DATETIME NULL,                 -- UTC; set only by a signed-in person (never by an automatic acknowledgement)
    first_response_by INT UNSIGNED NULL,
    first_response_kind VARCHAR(16) NULL,            -- reply | call | meeting | consultation
    created_at DATETIME NOT NULL,
    PRIMARY KEY (lead_id),
    KEY idx_crm_resp_open (first_response_at, due_at),
    KEY idx_crm_resp_band (priority_band, first_response_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Consultations (calls/meetings) linked to a lead. Times are UTC; the CRM shows Europe/Berlin.
CREATE TABLE IF NOT EXISTS crm_consultations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id BIGINT UNSIGNED NOT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    status VARCHAR(12) NOT NULL DEFAULT 'scheduled', -- scheduled | held | cancelled | no_show
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_cons_when (status, scheduled_at),
    KEY idx_crm_cons_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Owner notifications already sent. The unique key is what prevents duplicates (a second attempt for the same kind/entity/period inserts nothing).
CREATE TABLE IF NOT EXISTS crm_notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(24) NOT NULL,                       -- digest | lead_overdue
    entity_type VARCHAR(16) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    period_key VARCHAR(24) NOT NULL,                 -- e.g. the Berlin date for the digest, 'v1' for a one-off alert
    sent_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_notif (kind, entity_type, entity_id, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
