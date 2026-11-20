-- Phase 8D: Finance. ADDITIVE ONLY: three new tables, every statement CREATE TABLE IF NOT EXISTS. No existing table (crm_*, submissions, tools, ...) is altered.
-- NOT EXECUTED by the development team: the owner's administrator runs it, after a backup, only with the owner's approval.
-- Nothing here is deleted by the application: a wrong record is voided with a reason. Amounts are integer minor units. VAT is not tracked.

CREATE TABLE IF NOT EXISTS fin_expense_entry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_date DATE NOT NULL,
    category VARCHAR(24) NOT NULL,
    vendor VARCHAR(80) NOT NULL DEFAULT '',
    description VARCHAR(190) NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    recurrence VARCHAR(8) NOT NULL DEFAULT 'none',
    recurrence_end DATE NULL,
    reference VARCHAR(60) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    voided_at DATETIME NULL,
    voided_by INT UNSIGNED NULL,
    void_reason VARCHAR(190) NOT NULL DEFAULT '',
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_fin_exp_date (entry_date),
    KEY idx_fin_exp_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Owner objectives, never forecasts. A new target for a month and metric supersedes the old one (history kept). active_slot makes "one active target per month and metric" a database rule.
CREATE TABLE IF NOT EXISTS fin_target (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    month DATE NOT NULL,
    metric VARCHAR(16) NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    label VARCHAR(80) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    superseded_at DATETIME NULL,
    active_slot VARCHAR(32) GENERATED ALWAYS AS (IF(superseded_at IS NULL, CONCAT(month, '|', metric), NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fin_target_active (active_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refunds and credit notes against a CRM contract. The CRM tables are not changed; this is a separate record.
CREATE TABLE IF NOT EXISTS fin_adjustment (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(8) NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    entry_date DATE NOT NULL,
    reference VARCHAR(60) NOT NULL DEFAULT '',
    note VARCHAR(190) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    voided_at DATETIME NULL,
    voided_by INT UNSIGNED NULL,
    void_reason VARCHAR(190) NOT NULL DEFAULT '',
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_fin_adj_contract (contract_id),
    KEY idx_fin_adj_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
