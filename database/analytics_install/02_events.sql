-- Analytics install step 2 of 3: the `events` table ONLY, extracted verbatim from database/phase3_business.sql (which also creates `leads` and `subscribers`; this file does NOT touch those).
-- Additive and idempotent (IF NOT EXISTS). Run by an administrator after review; the application never runs it.
CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at DATETIME NOT NULL,
    day DATE NOT NULL,
    name VARCHAR(32) NOT NULL,
    page_type VARCHAR(24) NOT NULL DEFAULT '',
    ref VARCHAR(80) NOT NULL DEFAULT '',
    prop1 VARCHAR(40) NOT NULL DEFAULT '',
    prop2 VARCHAR(40) NOT NULL DEFAULT '',
    visitor_hash CHAR(16) NOT NULL DEFAULT '',
    returning_user TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_events_day_name (day, name),
    KEY idx_events_ref (name, ref(40))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
