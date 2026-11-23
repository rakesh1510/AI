-- OPTIONAL, STAGING-ONLY, only when the owner asks for it: the application writes `tool_clicks` rows only when BOTH interaction tables exist in the current shape
-- (helpers.php interaction_tables_exist() requires tool_votes too). Without tool_votes the outbound click is still counted in `events` (the analytics source of truth) but no `tool_clicks` row is written,
-- so the "one click = one tool_clicks row" check cannot be done. Additive and idempotent; creates an EMPTY table; copies nothing. Same definition as tests/fixtures/interactions_current.sql.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
