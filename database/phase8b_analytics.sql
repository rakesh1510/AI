-- Phase 8B analytics rollups (ADDITIVE ONLY). Run by an administrator after review; the application never runs it.
-- Creates five new tables; page_views, events, tool_clicks and every other existing table are NOT altered. Idempotent (IF NOT EXISTS).
-- Rollback: database/phase8b_rollback.sql (drops these five tables only). Filled by scripts/adm_rollup.php from real rows; nothing is estimated or invented.
-- Metric definitions: human page view = page_views row with is_bot = 0 and status < 400; visitor = distinct non-empty visitor_hash per day (the hash rotates daily, so
-- a multi-day sum is "visitor-days", not people); session (approximate) = a daily hash's human views split by more than 30 minutes of inactivity.

CREATE TABLE IF NOT EXISTS adm_traffic_daily (
    day DATE NOT NULL,
    views_human INT UNSIGNED NOT NULL DEFAULT 0,
    views_bot INT UNSIGNED NOT NULL DEFAULT 0,
    views_no_id INT UNSIGNED NOT NULL DEFAULT 0,
    views_error INT UNSIGNED NOT NULL DEFAULT 0,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adm_traffic_dim_daily (
    day DATE NOT NULL,
    dim VARCHAR(40) NOT NULL,
    dim_key VARCHAR(190) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, dim, dim_key),
    KEY idx_adm_dim_dim_day (dim, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adm_tool_daily (
    day DATE NOT NULL,
    tool_slug VARCHAR(120) NOT NULL,
    tool_views INT UNSIGNED NOT NULL DEFAULT 0,
    outbound_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    affiliate_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    sponsored_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, tool_slug),
    KEY idx_adm_tool_slug_day (tool_slug, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adm_event_daily (
    day DATE NOT NULL,
    name VARCHAR(32) NOT NULL,
    ref VARCHAR(80) NOT NULL DEFAULT '',
    events INT UNSIGNED NOT NULL DEFAULT 0,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, name, ref),
    KEY idx_adm_event_name_day (name, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adm_rollup_run (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job VARCHAR(24) NOT NULL,
    day_from DATE NOT NULL,
    day_to DATE NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    rows_written INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL DEFAULT 'running',
    note VARCHAR(190) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_adm_run_job (job, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
