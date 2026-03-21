CREATE TABLE IF NOT EXISTS compare_tool_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tool_slug VARCHAR(191) NOT NULL,
  tool_name VARCHAR(191) NOT NULL,
  official_website TEXT NULL,
  pricing_model VARCHAR(191) NULL,
  free_plan TINYINT(1) NULL,
  free_trial TINYINT(1) NULL,
  platforms_json JSON NULL,
  integrations_json JSON NULL,
  summary TEXT NULL,
  source_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  source_confidence VARCHAR(50) NOT NULL DEFAULT 'basic',
  source_hash CHAR(64) NULL,
  last_synced_at DATETIME NULL,
  last_enriched_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_compare_tool_slug (tool_slug),
  KEY idx_compare_tool_name (tool_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compare_tool_points (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tool_slug VARCHAR(191) NOT NULL,
  point_type VARCHAR(50) NOT NULL,
  label VARCHAR(255) NOT NULL,
  source_url TEXT NULL,
  source_note VARCHAR(255) NULL,
  evidence_snippet TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_compare_points_slug_type (tool_slug, point_type),
  KEY idx_compare_points_slug (tool_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compare_tool_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tool_slug VARCHAR(191) NOT NULL,
  source_type VARCHAR(50) NOT NULL,
  source_url TEXT NOT NULL,
  http_status INT NULL,
  content_hash CHAR(64) NULL,
  raw_html LONGTEXT NULL,
  extracted_text LONGTEXT NULL,
  fetched_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_compare_sources_slug (tool_slug),
  KEY idx_compare_sources_slug_type (tool_slug, source_type(20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
