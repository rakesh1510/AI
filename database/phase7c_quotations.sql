-- Phase 7C: professional quotations (additive, idempotent; apply AFTER database/phase6_crm.sql). No existing table is changed; no foreign keys (CRM stores plain ids).
-- Rollback: database/phase7c_rollback.sql (drops only these four tables).

-- Per proposal version: tax label shown on the quotation, customer-facing intro and terms.
CREATE TABLE IF NOT EXISTS crm_proposal_extra (
    version_id BIGINT UNSIGNED NOT NULL,
    tax_label VARCHAR(60) NOT NULL DEFAULT '',
    intro TEXT NULL,
    customer_terms TEXT NULL,
    PRIMARY KEY (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment milestones of a proposal version (percent of the version total; the amounts are fixed when the version is saved).
CREATE TABLE IF NOT EXISTS crm_proposal_milestones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    pct_bp INT UNSIGNED NOT NULL,
    amount_minor BIGINT NOT NULL,
    due_kind VARCHAR(24) NOT NULL DEFAULT 'on_acceptance',
    due_days SMALLINT UNSIGNED NULL,
    due_date DATE NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_ms (version_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What happened to a proposal outside this system: sent to the customer, accepted, declined (who, when, how, why). Nothing here sends anything.
CREATE TABLE IF NOT EXISTS crm_proposal_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    kind VARCHAR(12) NOT NULL,
    event_date DATE NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'other',
    contact VARCHAR(120) NOT NULL DEFAULT '',
    reference VARCHAR(190) NOT NULL DEFAULT '',
    reason VARCHAR(255) NOT NULL DEFAULT '',
    recorded_by INT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_pev (proposal_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gap-free per-year quotation numbering (Q-2026-0001): one row per year, incremented atomically.
CREATE TABLE IF NOT EXISTS crm_quote_counter (
    year_no SMALLINT UNSIGNED NOT NULL,
    last_seq INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (year_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
