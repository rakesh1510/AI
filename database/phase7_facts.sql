-- Phase 7 facts: source-backed, human-verified comparison facts for the AI Battle Arena (tables uni_fact, uni_fact_source, uni_fact_review).
-- Requires database/phase7_universe.sql (uni_entity, uni_release, uni_source) and Phase 6 (crm_users). A SEPARATE migration: phase7_universe.sql and its test are untouched.
-- ADDITIVE ONLY: three CREATE TABLE IF NOT EXISTS statements. No existing table is altered. Safe to run twice. Rollback: database/phase7_facts_rollback.sql (manual, destructive).
-- Do NOT run against a live database before the owner has reviewed it and taken a backup. Targets MySQL 8.0 / 8.4 and MariaDB 10.11, utf8mb4_unicode_ci, InnoDB.
--
-- WHAT A FACT IS: one claim about one entity (optionally tied to one release): a feature, platform, integration, pricing point, benchmark result or limit.
-- It carries a text value OR a numeric value with a unit (never both), the date it was observed, and at least one quoted source before it can be verified.
-- There is NO score, rank, rating or winner column anywhere. A benchmark is only the figure a verified source reports, with its metric, version and conditions.
-- Same one state vocabulary and the same lineage / version / supersede model as uni_event: proposed, in_review, verified, rejected, superseded; origin human, ai_assisted, import.
-- Single-row rules are CHECK constraints (case-sensitive state / origin / fact_type, trailing-space checks, import never verified, maker is not checker,
--   verified needs verified_by + verified_at + review_id, text xor numeric value, a unit exactly when a number is stored, pricing needs a number + unit,
--   a benchmark needs a number + unit + benchmark name + metric + version, non-benchmark facts carry no benchmark fields, date precision rules).
-- Cross-table rules (at least one source, reviewer holds the verifier role, review matches the target, verified rows immutable and undeletable, frozen sources)
--   live in database/phase7_facts_triggers.sql, an OPTIONAL file. PRIMARY ENFORCEMENT IS THE PHP SERVICE LAYER (uni_core.php).
-- uni_fact_review is the append-only review ledger of facts. It is a separate table so that the existing uni_review CHECK (target types) never has to be altered.
-- Foreign keys: ON DELETE RESTRICT and ON UPDATE RESTRICT everywhere. Nothing cascades. Rows are never hard-deleted: they are superseded.
-- Reading rule for every public query: WHERE state = 'verified' AND is_current = 1, on a verified current entity.

CREATE TABLE IF NOT EXISTS uni_fact_review (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type VARCHAR(12) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    proposed_by INT UNSIGNED NOT NULL,
    decision VARCHAR(20) NOT NULL,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    decided_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uni_fact_review_once (target_type, target_id, reviewer_id),
    KEY idx_uni_fact_review_reviewer (reviewer_id),
    KEY idx_uni_fact_review_proposed_by (proposed_by),
    CONSTRAINT chk_uni_fact_review_target CHECK (target_type COLLATE utf8mb4_bin = 'fact'),
    CONSTRAINT chk_uni_fact_review_decision CHECK (decision COLLATE utf8mb4_bin IN ('accept','reject','needs_second_source')),
    CONSTRAINT chk_uni_fact_review_maker_checker CHECK (proposed_by <> reviewer_id),
    CONSTRAINT fk_uni_fact_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES crm_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_review_proposed_by FOREIGN KEY (proposed_by) REFERENCES crm_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uni_fact (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_id BIGINT UNSIGNED NOT NULL,
    release_id BIGINT UNSIGNED NULL,
    fact_type VARCHAR(12) NOT NULL,
    fact_key VARCHAR(120) NOT NULL,
    value_text VARCHAR(1000) NOT NULL DEFAULT '',
    value_num DECIMAL(24,6) NULL,
    unit VARCHAR(40) NOT NULL DEFAULT '',
    benchmark_name VARCHAR(120) NOT NULL DEFAULT '',
    benchmark_metric VARCHAR(120) NOT NULL DEFAULT '',
    benchmark_version VARCHAR(80) NOT NULL DEFAULT '',
    conditions VARCHAR(1000) NOT NULL DEFAULT '',
    observed_on DATE NOT NULL,
    date_precision VARCHAR(5) NOT NULL,
    state VARCHAR(12) NOT NULL DEFAULT 'proposed',
    origin VARCHAR(12) NOT NULL DEFAULT 'human',
    proposed_by INT UNSIGNED NOT NULL,
    proposed_at DATETIME NOT NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    review_id BIGINT UNSIGNED NULL,
    lineage_key VARCHAR(32) NOT NULL,
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    supersedes_id BIGINT UNSIGNED NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    current_lineage VARCHAR(32) GENERATED ALWAYS AS (IF(is_current = 1, lineage_key, NULL)) STORED,
    PRIMARY KEY (id),
    KEY idx_uni_fact_entity (entity_id, fact_type),
    KEY idx_uni_fact_release (release_id),
    KEY idx_uni_fact_public (state, is_current, fact_type),
    UNIQUE KEY uq_uni_fact_current (current_lineage),
    UNIQUE KEY uq_uni_fact_lineage_ver (lineage_key, version_no),
    KEY idx_uni_fact_proposed_by (proposed_by),
    KEY idx_uni_fact_verified_by (verified_by),
    KEY idx_uni_fact_review (review_id),
    KEY idx_uni_fact_supersedes (supersedes_id),
    CONSTRAINT chk_uni_fact_type CHECK (CHAR_LENGTH(fact_type) = CHAR_LENGTH(TRIM(fact_type)) AND fact_type COLLATE utf8mb4_bin IN ('feature','platform','integration','pricing','benchmark','limit')),
    CONSTRAINT chk_uni_fact_key CHECK (CHAR_LENGTH(TRIM(REPLACE(REPLACE(REPLACE(fact_key, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))) > 0 AND CHAR_LENGTH(fact_key) = CHAR_LENGTH(TRIM(fact_key))),
    CONSTRAINT chk_uni_fact_value_xor CHECK ((value_num IS NULL AND CHAR_LENGTH(TRIM(REPLACE(REPLACE(REPLACE(value_text, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))) > 0) OR (value_num IS NOT NULL AND CHAR_LENGTH(TRIM(value_text)) = 0)),
    CONSTRAINT chk_uni_fact_unit CHECK (CHAR_LENGTH(unit) = CHAR_LENGTH(TRIM(unit)) AND ((value_num IS NULL AND unit = '') OR (value_num IS NOT NULL AND CHAR_LENGTH(unit) > 0))),
    CONSTRAINT chk_uni_fact_bench_trim CHECK (CHAR_LENGTH(benchmark_name) = CHAR_LENGTH(TRIM(benchmark_name)) AND CHAR_LENGTH(benchmark_metric) = CHAR_LENGTH(TRIM(benchmark_metric)) AND CHAR_LENGTH(benchmark_version) = CHAR_LENGTH(TRIM(benchmark_version))),
    CONSTRAINT chk_uni_fact_pricing CHECK (fact_type <> 'pricing' OR (value_num IS NOT NULL AND value_num >= 0 AND CHAR_LENGTH(unit) > 0)),
    CONSTRAINT chk_uni_fact_benchmark CHECK (fact_type <> 'benchmark' OR (value_num IS NOT NULL AND CHAR_LENGTH(unit) > 0 AND CHAR_LENGTH(benchmark_name) > 0 AND CHAR_LENGTH(benchmark_metric) > 0 AND CHAR_LENGTH(benchmark_version) > 0)),
    CONSTRAINT chk_uni_fact_text_types CHECK (fact_type NOT IN ('feature','platform','integration') OR value_num IS NULL),
    CONSTRAINT chk_uni_fact_bench_only CHECK (fact_type = 'benchmark' OR (benchmark_name = '' AND benchmark_metric = '' AND benchmark_version = '')),
    CONSTRAINT chk_uni_fact_conditions CHECK (CHAR_LENGTH(conditions) = CHAR_LENGTH(TRIM(conditions))),
    CONSTRAINT chk_uni_fact_precision CHECK (date_precision COLLATE utf8mb4_bin IN ('day','month','year')),
    CONSTRAINT chk_uni_fact_date_range CHECK (observed_on >= '1900-01-01' AND observed_on <= '2100-12-31'),
    CONSTRAINT chk_uni_fact_month_first CHECK (date_precision <> 'month' OR DAY(observed_on) = 1),
    CONSTRAINT chk_uni_fact_year_jan1 CHECK (date_precision <> 'year' OR (MONTH(observed_on) = 1 AND DAY(observed_on) = 1)),
    CONSTRAINT chk_uni_fact_state CHECK (CHAR_LENGTH(state) = CHAR_LENGTH(TRIM(state)) AND state COLLATE utf8mb4_bin IN ('proposed','in_review','verified','rejected','superseded')),
    CONSTRAINT chk_uni_fact_origin CHECK (CHAR_LENGTH(origin) = CHAR_LENGTH(TRIM(origin)) AND origin COLLATE utf8mb4_bin IN ('human','ai_assisted','import')),
    CONSTRAINT chk_uni_fact_verified_fields CHECK (state <> 'verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL AND review_id IS NOT NULL)),
    CONSTRAINT chk_uni_fact_open_clean CHECK (state NOT IN ('proposed','in_review') OR (verified_by IS NULL AND verified_at IS NULL)),
    CONSTRAINT chk_uni_fact_import_not_verified CHECK (NOT (origin = 'import' AND state = 'verified')),
    CONSTRAINT chk_uni_fact_maker_checker CHECK (verified_by IS NULL OR verified_by <> proposed_by),
    CONSTRAINT chk_uni_fact_current_flag CHECK (is_current IN (0,1) AND (state NOT IN ('superseded','rejected') OR is_current = 0)),
    CONSTRAINT chk_uni_fact_version CHECK (version_no >= 1 AND ((version_no = 1) = (supersedes_id IS NULL))),
    CONSTRAINT fk_uni_fact_entity FOREIGN KEY (entity_id) REFERENCES uni_entity (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_release FOREIGN KEY (release_id) REFERENCES uni_release (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_proposed_by FOREIGN KEY (proposed_by) REFERENCES crm_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_verified_by FOREIGN KEY (verified_by) REFERENCES crm_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_review FOREIGN KEY (review_id) REFERENCES uni_fact_review (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_supersedes FOREIGN KEY (supersedes_id) REFERENCES uni_fact (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uni_fact_source (
    fact_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (fact_id, source_id),
    KEY idx_uni_fact_source_source (source_id),
    CONSTRAINT fk_uni_fact_source_fact FOREIGN KEY (fact_id) REFERENCES uni_fact (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uni_fact_source_source FOREIGN KEY (source_id) REFERENCES uni_source (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
