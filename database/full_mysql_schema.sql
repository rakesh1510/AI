CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(190) NOT NULL UNIQUE,
    setting_value LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    category_id INT NULL,
    short_description TEXT NULL,
    description LONGTEXT NULL,
    features LONGTEXT NULL,
    pros LONGTEXT NULL,
    cons LONGTEXT NULL,
    use_cases LONGTEXT NULL,
    pricing VARCHAR(255) NULL,
    website VARCHAR(1000) NULL,
    affiliate VARCHAR(1000) NULL,
    added VARCHAR(100) NULL,
    tags LONGTEXT NULL,
    logo VARCHAR(1000) NULL,
    pricing_type VARCHAR(100) NULL,
    seo_title VARCHAR(255) NULL,
    seo_description TEXT NULL,
    featured_level TINYINT NOT NULL DEFAULT 0,
    featured_until DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tools_category (category_id),
    INDEX idx_tools_status (status),
    CONSTRAINT fk_tools_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    excerpt TEXT NULL,
    content LONGTEXT NULL,
    published_at DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS use_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    label VARCHAR(255) NOT NULL,
    keywords_json LONGTEXT NULL,
    fallback_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tool_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_slug VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tool_clicks_slug (tool_slug),
    INDEX idx_tool_clicks_created (created_at)
);

CREATE TABLE IF NOT EXISTS tool_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_slug VARCHAR(255) NOT NULL,
    vote_type ENUM('up','down','like','dislike') NOT NULL DEFAULT 'up',
    visitor_id VARCHAR(64) DEFAULT NULL,
    ip_hash VARCHAR(64) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tool_vote_visitor (tool_slug, visitor_id),
    INDEX idx_tool_votes_slug (tool_slug)
);

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(255) NOT NULL,
    website VARCHAR(1000) NOT NULL,
    short_desc TEXT NOT NULL,
    plan VARCHAR(50) NOT NULL DEFAULT 'free',
    price INT NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    payment_status VARCHAR(50) NOT NULL DEFAULT 'not_required',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_submission_id (submission_id),
    INDEX idx_submissions_status (status),
    INDEX idx_submissions_plan (plan)
);
