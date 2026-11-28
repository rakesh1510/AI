-- Phase 7E: affiliate revenue engine (additive, idempotent; independent of other phases, needs only the existing tools catalogue). No existing table is changed; no foreign keys.
-- Rollback: database/phase7e_rollback.sql (drops only these tables; the tools catalogue and analytics are untouched).
-- The network rows below are only NAMES of well-known affiliate networks so they can be chosen in a list. No partnership, commission rate or approval is implied or invented.

CREATE TABLE IF NOT EXISTS aff_network (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(24) NOT NULL,
    name VARCHAR(80) NOT NULL,
    website VARCHAR(255) NOT NULL DEFAULT '',
    subid_param VARCHAR(40) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_aff_network_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO aff_network (code, name, created_at) VALUES ('awin', 'Awin', UTC_TIMESTAMP()), ('impact', 'Impact', UTC_TIMESTAMP()), ('partnerstack', 'PartnerStack', UTC_TIMESTAMP()), ('cj', 'CJ Affiliate', UTC_TIMESTAMP()), ('rakuten', 'Rakuten Advertising', UTC_TIMESTAMP()), ('direct', 'Direct program (company)', UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS aff_partner (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tool_slug VARCHAR(120) NOT NULL DEFAULT '',
    company VARCHAR(190) NOT NULL,
    network_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'researching',
    application_url VARCHAR(1000) NOT NULL DEFAULT '',
    program_url VARCHAR(1000) NOT NULL DEFAULT '',
    commission_structure VARCHAR(255) NOT NULL DEFAULT '',
    commission_type VARCHAR(12) NOT NULL DEFAULT 'unknown',
    commission_currency CHAR(3) NULL,
    cookie_days SMALLINT UNSIGNED NULL,
    payment_threshold_minor BIGINT NULL,
    payment_schedule VARCHAR(120) NOT NULL DEFAULT '',
    terms TEXT NULL,
    restrictions TEXT NULL,
    terms_checked_at DATE NULL,
    verified_at DATE NULL,
    verified_source VARCHAR(255) NOT NULL DEFAULT '',
    applied_at DATE NULL,
    follow_up_at DATE NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_aff_partner_tool (tool_slug),
    KEY idx_aff_partner_status (status),
    KEY idx_aff_partner_network (network_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aff_partner_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(16) NULL,
    to_status VARCHAR(16) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_aff_ph (partner_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per affiliate link. At most ONE link per tool can be active: active_tool holds the tool slug while active (NULL otherwise) under a unique key.
CREATE TABLE IF NOT EXISTS aff_link (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NOT NULL,
    tool_slug VARCHAR(120) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    tracking_id VARCHAR(80) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'inactive',
    active_tool VARCHAR(120) NULL,
    verify_result VARCHAR(8) NULL,
    last_verified_at DATE NULL,
    verify_note VARCHAR(255) NOT NULL DEFAULT '',
    activated_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_aff_link_active_tool (active_tool),
    KEY idx_aff_link_partner (partner_id),
    KEY idx_aff_link_tool (tool_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aff_link_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    link_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(16) NOT NULL,
    old_url VARCHAR(1000) NULL,
    new_url VARCHAR(1000) NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_aff_lh (link_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Commission transactions as reported by a network or program. The pair (partner, transaction reference) is unique, so a transaction can be imported only once.
-- 'paid' = money actually received; 'approved' is only a commitment. A paid or approved commission reversed later keeps its row (status reversed, reversed_at).
CREATE TABLE IF NOT EXISTS aff_commission (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NOT NULL,
    tool_slug VARCHAR(120) NOT NULL DEFAULT '',
    transaction_ref VARCHAR(120) NOT NULL,
    transaction_date DATE NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    paid_at DATE NULL,
    reversed_at DATE NULL,
    payout_ref VARCHAR(120) NOT NULL DEFAULT '',
    source VARCHAR(8) NOT NULL DEFAULT 'manual',
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_aff_commission_ref (partner_id, transaction_ref),
    KEY idx_aff_comm_date (transaction_date),
    KEY idx_aff_comm_status (status),
    KEY idx_aff_comm_tool (tool_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aff_commission_event (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commission_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(10) NULL,
    to_status VARCHAR(10) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_aff_ce (commission_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
