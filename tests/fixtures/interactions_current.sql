-- Test-only fixture matching the column shape the PHP code actually uses
-- (visitor_id / ip_hash / 'up'|'down'). Synthetic data only.
CREATE TABLE IF NOT EXISTS tool_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_slug VARCHAR(255) NOT NULL,
    visitor_id VARCHAR(64) DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tool_clicks_slug (tool_slug),
    INDEX idx_tool_clicks_created (created_at)
);
CREATE TABLE IF NOT EXISTS tool_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_slug VARCHAR(255) NOT NULL,
    visitor_id CHAR(32) NULL,
    vote_type VARCHAR(10) NOT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tool_vote_visitor (tool_slug, visitor_id),
    INDEX idx_tool_votes_slug (tool_slug)
);
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
