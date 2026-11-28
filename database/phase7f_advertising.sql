-- Phase 7F: advertising and sponsorship revenue engine (additive, idempotent; apply AFTER database/phase6_crm.sql). No existing table is changed; no foreign keys.
-- Rollback: database/phase7f_rollback.sql (drops only these tables; CRM campaigns, contracts, revenue entries and tool placements are untouched).
-- The five package rows below are PROPOSED structures to be reviewed and priced by the owner. They are NOT published and do not change any public page or price.

CREATE TABLE IF NOT EXISTS ad_package (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(100) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    price_minor BIGINT NULL,
    currency CHAR(3) NULL,
    duration_days SMALLINT UNSIGNED NULL,
    billing VARCHAR(10) NOT NULL DEFAULT 'one_off',
    placement VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    slots SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    exclusive TINYINT(1) NOT NULL DEFAULT 0,
    scope VARCHAR(10) NOT NULL DEFAULT 'global',
    public_slot VARCHAR(8) NOT NULL DEFAULT 'none',
    available TINYINT(1) NOT NULL DEFAULT 0,
    published TINYINT(1) NOT NULL DEFAULT 0,
    proposed TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ad_package_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO ad_package (code, name, kind, price_minor, currency, duration_days, billing, placement, description, slots, exclusive, scope, public_slot, available, published, proposed, sort_order, created_at, updated_at) VALUES
 ('featured_tool', 'Featured Tool', 'featured_tool', 4900, 'USD', 30, 'one_off', 'Labelled "Sponsored" listing on the homepage (existing featured placement, level 1)', 'PROPOSED price for review: 49 USD for 30 days. Not published.', 3, 0, 'global', 'level1', 1, 0, 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('category_sponsor', 'Category Sponsor', 'category_sponsor', 14900, 'USD', NULL, 'monthly', 'Sponsor of one category (no public slot exists yet: booking, billing and reporting only)', 'PROPOSED price for review: 149 USD per month. Not published. A public category slot does not exist yet.', 1, 1, 'category', 'none', 0, 0, 1, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('homepage_spotlight', 'Homepage Spotlight', 'homepage_spotlight', 29900, 'USD', NULL, 'monthly', 'Top labelled "Sponsored" homepage spotlight (existing featured placement, level 2)', 'PROPOSED price for review: 299 USD per month. Not published.', 1, 1, 'global', 'level2', 1, 0, 1, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('newsletter_sponsor', 'Newsletter Sponsor', 'newsletter_sponsor', 9900, 'USD', NULL, 'per_issue', 'Sponsor slot in an AIHubpedia e-mail', 'PROPOSED price for review: 99 USD per issue. Not available: the newsletter has not launched, so no audience figure exists.', 1, 1, 'global', 'none', 0, 0, 1, 40, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('enterprise', 'Custom Enterprise Sponsorship', 'enterprise', NULL, NULL, NULL, 'custom', 'Agreed individually in writing', 'Scope, price and duration are agreed with the advertiser; no standard price.', 1, 0, 'none', 'none', 1, 0, 0, 50, UTC_TIMESTAMP(), UTC_TIMESTAMP());

-- One booking per CRM campaign (a campaign is the existing record of an advertising enquiry). Status is the advertising lifecycle; money lives in the existing contract and revenue entries.
CREATE TABLE IF NOT EXISTS ad_booking (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    package_id BIGINT UNSIGNED NULL,
    tool_slug VARCHAR(120) NOT NULL DEFAULT '',
    category VARCHAR(120) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    agreed_price_minor BIGINT NULL,
    currency CHAR(3) NULL,
    billing VARCHAR(10) NOT NULL DEFAULT 'one_off',
    start_date DATE NULL,
    end_date DATE NULL,
    renewal_date DATE NULL,
    payment_link VARCHAR(500) NOT NULL DEFAULT '',
    opportunity_id BIGINT UNSIGNED NULL,
    proposal_id BIGINT UNSIGNED NULL,
    contract_id BIGINT UNSIGNED NULL,
    account_manager INT UNSIGNED NULL,
    requirements TEXT NULL,
    notes TEXT NULL,
    placement_id BIGINT UNSIGNED NULL,
    activation_approved_by INT UNSIGNED NULL,
    activation_approved_at DATETIME NULL,
    payment_override VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ad_booking_campaign (campaign_id),
    KEY idx_ad_booking_company (company_id),
    KEY idx_ad_booking_status (status),
    KEY idx_ad_booking_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_booking_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ad_bh (booking_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Advertiser profile on top of an existing CRM company (account manager, internal notes) and the AI tools the company owns.
CREATE TABLE IF NOT EXISTS ad_advertiser (
    company_id BIGINT UNSIGNED NOT NULL,
    account_manager INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ad_advertiser_tool (
    company_id BIGINT UNSIGNED NOT NULL,
    tool_slug VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (company_id, tool_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
