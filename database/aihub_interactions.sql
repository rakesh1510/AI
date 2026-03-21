
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
    vote_type ENUM('like','dislike') NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tool_vote_ip (tool_slug, ip_address),
    INDEX idx_tool_votes_slug (tool_slug)
);
