-- First-party, privacy-preserving page-view log (Phase 2). Stores no IP addresses and no cookies.
-- visitor_hash = first 16 hex chars of sha256(secret salt + day/week + IP + user agent); it rotates, so it cannot track people across periods.
CREATE TABLE IF NOT EXISTS page_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    viewed_at DATETIME NOT NULL,
    day DATE NOT NULL,
    path VARCHAR(190) NOT NULL,
    page_type VARCHAR(24) NOT NULL,
    status SMALLINT NOT NULL DEFAULT 200,
    referrer_class VARCHAR(24) NOT NULL DEFAULT 'direct',
    referrer_host VARCHAR(120) NOT NULL DEFAULT '',
    country CHAR(2) NOT NULL DEFAULT 'XX',
    visitor_hash CHAR(16) NOT NULL DEFAULT '',
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    bot_type VARCHAR(20) NOT NULL DEFAULT '',
    bot_name VARCHAR(40) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_pv_day_bot (day, is_bot),
    KEY idx_pv_day_ref (day, referrer_class),
    KEY idx_pv_path (path(100), day),
    KEY idx_pv_status (status, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
