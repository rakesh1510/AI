-- Phase 7D: corporate customer acquisition (additive, idempotent; apply AFTER database/phase6_crm.sql). No existing table is changed; no foreign keys.
-- Rollback: database/phase7d_rollback.sql (drops only these three tables; the Phase 6 crm_prospects rows stay).

-- Company-level acquisition profile of a Phase 6 prospect (crm_prospects). Company data only: no personal contact details are stored here.
CREATE TABLE IF NOT EXISTS crm_prospect_profile (
    prospect_id BIGINT UNSIGNED NOT NULL,
    country CHAR(2) NOT NULL DEFAULT '',
    region VARCHAR(80) NOT NULL DEFAULT '',
    industry VARCHAR(40) NOT NULL DEFAULT '',
    size_band VARCHAR(12) NOT NULL DEFAULT '',
    requirements TEXT NULL,
    automation_notes TEXT NULL,
    services VARCHAR(255) NOT NULL DEFAULT '',
    est_value_minor BIGINT NULL,
    est_currency CHAR(3) NOT NULL DEFAULT 'EUR',
    est_basis VARCHAR(255) NOT NULL DEFAULT '',
    budget_info VARCHAR(20) NOT NULL DEFAULT 'unknown',
    urgency VARCHAR(12) NOT NULL DEFAULT 'unknown',
    urgency_note VARCHAR(255) NOT NULL DEFAULT '',
    sources TEXT NULL,
    next_action VARCHAR(255) NOT NULL DEFAULT '',
    next_action_at DATE NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'identified',
    contact_basis VARCHAR(24) NOT NULL DEFAULT 'none',
    contact_basis_note VARCHAR(255) NOT NULL DEFAULT '',
    lead_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (prospect_id),
    UNIQUE KEY uniq_crm_pp_lead (lead_id),
    KEY idx_crm_pp_status (status),
    KEY idx_crm_pp_country (country),
    KEY idx_crm_pp_industry (industry),
    KEY idx_crm_pp_next (next_action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_prospect_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prospect_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(24) NULL,
    to_status VARCHAR(24) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_psh (prospect_id, id),
    KEY idx_crm_psh_to (to_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Domains that must never be contacted (set automatically by "Do not contact"). Checked before drafts, status moves and imports.
CREATE TABLE IF NOT EXISTS crm_prospect_suppressions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(190) NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_supp_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
