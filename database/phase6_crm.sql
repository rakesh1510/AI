-- Phase 6: CRM, sales pipeline, proposals, campaigns, partnerships, business development.
-- ADDITIVE ONLY: every statement is CREATE TABLE IF NOT EXISTS. No existing table is altered, renamed or dropped, so existing tool,
-- editorial, engagement and lead data is untouched. Safe to run twice. Do NOT run against a live database before the owner has reviewed
-- docs/phase6/DATABASE_COMPATIBILITY_AND_MIGRATIONS.md and taken a backup. Rollback: database/phase6_rollback.sql (manual, destructive).
-- Amounts are integer minor units (cents) with a 3-letter currency code; there are no floating-point money columns.
-- Personal data is limited to business contact details given by the contact; no IP address or user agent is stored here.

CREATE TABLE IF NOT EXISTS crm_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(40) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'viewer',
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    domain VARCHAR(190) NOT NULL DEFAULT '',
    website VARCHAR(255) NOT NULL DEFAULT '',
    country CHAR(2) NULL,
    segment VARCHAR(40) NOT NULL DEFAULT '',
    source VARCHAR(60) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_companies_domain (domain),
    KEY idx_crm_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    email_norm VARCHAR(190) NOT NULL,
    role_title VARCHAR(80) NOT NULL DEFAULT '',
    contact_pref VARCHAR(16) NOT NULL DEFAULT 'email',
    consent_status VARCHAR(20) NOT NULL DEFAULT 'inbound_inquiry',
    consent_at DATETIME NULL,
    consent_source VARCHAR(80) NOT NULL DEFAULT '',
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_contacts_email (email_norm),
    KEY idx_crm_contacts_company (company_id),
    CONSTRAINT fk_crm_contacts_company FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_leads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL,
    subtype VARCHAR(40) NOT NULL DEFAULT '',
    company_id BIGINT UNSIGNED NULL,
    contact_id BIGINT UNSIGNED NULL,
    source VARCHAR(60) NOT NULL DEFAULT '',
    attr_source VARCHAR(40) NOT NULL DEFAULT '',
    attr_medium VARCHAR(40) NOT NULL DEFAULT '',
    attr_campaign VARCHAR(60) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    qualification TEXT NULL,
    assigned_to INT UNSIGNED NULL,
    budget_range VARCHAR(40) NOT NULL DEFAULT '',
    timeline VARCHAR(40) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    details MEDIUMTEXT NULL,
    public_lead_id BIGINT UNSIGNED NULL,
    duplicate_of BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_leads_status (status, created_at),
    KEY idx_crm_leads_kind (kind, created_at),
    KEY idx_crm_leads_company (company_id),
    KEY idx_crm_leads_contact (contact_id),
    KEY idx_crm_leads_assignee (assigned_to),
    KEY idx_crm_leads_campaign (attr_campaign),
    CONSTRAINT fk_crm_leads_company FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_leads_contact FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_leads_assignee FOREIGN KEY (assigned_to) REFERENCES crm_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_opportunities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    kind VARCHAR(16) NOT NULL,
    title VARCHAR(190) NOT NULL,
    stage VARCHAR(16) NOT NULL DEFAULT 'new',
    amount_minor BIGINT NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    expected_close DATE NULL,
    owner_id INT UNSIGNED NULL,
    outcome VARCHAR(8) NULL,
    lost_reason VARCHAR(190) NOT NULL DEFAULT '',
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_opp_stage (stage),
    KEY idx_crm_opp_company (company_id),
    KEY idx_crm_opp_owner (owner_id),
    KEY idx_crm_opp_kind (kind, stage),
    CONSTRAINT fk_crm_opp_company FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_opp_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_opp_owner FOREIGN KEY (owner_id) REFERENCES crm_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_stage_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    from_stage VARCHAR(16) NOT NULL,
    to_stage VARCHAR(16) NOT NULL,
    user_id INT UNSIGNED NULL,
    at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_stage_opp (opportunity_id, at),
    CONSTRAINT fk_crm_stage_opp FOREIGN KEY (opportunity_id) REFERENCES crm_opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(16) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'note',
    body TEXT NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_act_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(16) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    due_at DATETIME NOT NULL,
    assignee_id INT UNSIGNED NULL,
    done_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_tasks_due (done_at, due_at),
    KEY idx_crm_tasks_assignee (assignee_id, done_at),
    KEY idx_crm_tasks_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(24) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_proposal_number (number),
    KEY idx_crm_proposal_opp (opportunity_id),
    CONSTRAINT fk_crm_proposal_opp FOREIGN KEY (opportunity_id) REFERENCES crm_opportunities (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_proposal_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    valid_until DATE NULL,
    discount_bp INT NOT NULL DEFAULT 0,
    tax_bp INT NOT NULL DEFAULT 0,
    subtotal_minor BIGINT NOT NULL,
    discount_minor BIGINT NOT NULL,
    tax_minor BIGINT NOT NULL,
    total_minor BIGINT NOT NULL,
    billing_period VARCHAR(10) NOT NULL DEFAULT 'one_off',
    terms TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_pv (proposal_id, version),
    CONSTRAINT fk_crm_pv_proposal FOREIGN KEY (proposal_id) REFERENCES crm_proposals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_proposal_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    qty_x100 INT NOT NULL,
    unit_minor BIGINT NOT NULL,
    discount_bp INT NOT NULL DEFAULT 0,
    line_total_minor BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_pi_version (version_id, position),
    CONSTRAINT fk_crm_pi_version FOREIGN KEY (version_id) REFERENCES crm_proposal_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NULL,
    opportunity_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    start_date DATE NULL,
    end_date DATE NULL,
    billing_period VARCHAR(10) NOT NULL DEFAULT 'monthly',
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    renewal_date DATE NULL,
    auto_renew TINYINT(1) NOT NULL DEFAULT 0,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(190) NOT NULL DEFAULT '',
    confirmed_by INT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_contract_status (status),
    KEY idx_crm_contract_company (company_id),
    KEY idx_crm_contract_renewal (renewal_date),
    CONSTRAINT fk_crm_contract_company FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_contract_proposal FOREIGN KEY (proposal_id) REFERENCES crm_proposals (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manual bookkeeping records ONLY. Nothing here issues an invoice or moves money; `reference` is an external document number (never card or bank data).
CREATE TABLE IF NOT EXISTS crm_revenue_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    entry_date DATE NOT NULL,
    reference VARCHAR(60) NOT NULL DEFAULT '',
    note VARCHAR(190) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_rev_contract (contract_id, kind),
    CONSTRAINT fk_crm_rev_contract FOREIGN KEY (contract_id) REFERENCES crm_contracts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NULL,
    contact_id BIGINT UNSIGNED NULL,
    package VARCHAR(32) NOT NULL DEFAULT '',
    title VARCHAR(190) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'inquiry',
    disclosure_label VARCHAR(40) NOT NULL DEFAULT '',
    landing_url VARCHAR(255) NOT NULL DEFAULT '',
    placement_notes TEXT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    attribution_code VARCHAR(40) NULL,
    quote_minor BIGINT NULL,
    quote_currency CHAR(3) NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    reject_reason VARCHAR(190) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_crm_campaign_code (attribution_code),
    KEY idx_crm_campaign_status (status),
    KEY idx_crm_campaign_company (company_id),
    CONSTRAINT fk_crm_campaign_company FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_partners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    category VARCHAR(24) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'applied',
    proposal TEXT NULL,
    public_listing VARCHAR(8) NOT NULL DEFAULT 'hidden',
    verified_at DATETIME NULL,
    verified_by INT UNSIGNED NULL,
    evidence_url VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_partner_status (status),
    KEY idx_crm_partner_company (company_id),
    CONSTRAINT fk_crm_partner_company FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_prospects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name VARCHAR(190) NOT NULL,
    website VARCHAR(255) NOT NULL DEFAULT '',
    domain VARCHAR(190) NOT NULL DEFAULT '',
    kind VARCHAR(16) NOT NULL DEFAULT 'advertising',
    research_notes TEXT NULL,
    source_url VARCHAR(255) NOT NULL DEFAULT '',
    fit_status VARCHAR(16) NOT NULL DEFAULT 'unqualified',
    owner_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_crm_prospect_domain (domain),
    KEY idx_crm_prospect_fit (fit_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outreach drafts have NO "sent" state by design: this release contains no code that sends outreach.
CREATE TABLE IF NOT EXISTS crm_outreach_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prospect_id BIGINT UNSIGNED NULL,
    contact_id BIGINT UNSIGNED NULL,
    subject VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_outreach_prospect (prospect_id),
    KEY idx_crm_outreach_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    at DATETIME NOT NULL,
    user_id INT UNSIGNED NULL,
    username VARCHAR(40) NOT NULL DEFAULT '',
    action VARCHAR(40) NOT NULL,
    entity_type VARCHAR(16) NOT NULL DEFAULT '',
    entity_id BIGINT UNSIGNED NULL,
    summary VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_crm_audit_at (at),
    KEY idx_crm_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
