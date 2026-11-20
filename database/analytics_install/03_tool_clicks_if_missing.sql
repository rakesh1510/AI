-- Analytics install, STAGING-ONLY helper: create an EMPTY `tool_clicks` table ONLY IF IT DOES NOT EXIST. Additive and idempotent (IF NOT EXISTS): if the table already exists this statement does nothing
-- and never alters it. No data is copied from anywhere; in particular production `aihub.tool_clicks` (71,502 rows) is never read or copied.
-- Shape = the five columns production has (id, tool_slug, visitor_id, ip_hash, created_at) in the repository's current definition (tests/fixtures/interactions_current.sql), which the application
-- already requires (helpers.php interaction_tables_exist()). Both identifier columns are NULL-able and receive empty strings from the privacy-fixed recorder. No UNIQUE index (a unique index
-- involving visitor_id or ip_hash would reject anonymous clicks). Compatible with MySQL 8.4 (utf8mb4, InnoDB, no deprecated syntax).
CREATE TABLE IF NOT EXISTS tool_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_slug VARCHAR(255) NOT NULL,
    visitor_id VARCHAR(64) DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tool_clicks_slug (tool_slug),
    INDEX idx_tool_clicks_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
