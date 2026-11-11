-- ==============================================================================
-- AIHubpedia Database Migration: Shared Immutable Submission ID
-- Adds submission_id column, backfills existing rows, and enforces unique index.
-- Safe for re-execution (idempotent).
-- ==============================================================================

SET @dbname = DATABASE();
SET @tablename = "submissions";
SET @columnname = "submission_id";

-- 1. Add submission_id column if not exists
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  "SELECT 'submission_id column already exists'",
  "ALTER TABLE submissions ADD COLUMN submission_id VARCHAR(64) NULL AFTER id"
));
PREPARE alterTableIfNotExists FROM @preparedStatement;
EXECUTE alterTableIfNotExists;
DEALLOCATE PREPARE alterTableIfNotExists;

-- 2. Backfill existing records that have NULL or empty submission_id with deterministic stable unique keys
UPDATE submissions 
SET submission_id = CONCAT('sub_legacy_', id, '_', SUBSTRING(MD5(CONCAT(id, name, created_at)), 1, 8))
WHERE submission_id IS NULL OR submission_id = '';

-- 3. Make column NOT NULL
ALTER TABLE submissions MODIFY COLUMN submission_id VARCHAR(64) NOT NULL;

-- 4. Add unique index if not exists
SET @indexname = "uniq_submission_id";
SET @preparedStatementIdx = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  "SELECT 'uniq_submission_id index already exists'",
  "ALTER TABLE submissions ADD UNIQUE KEY uniq_submission_id (submission_id)"
));
PREPARE addIdxIfNotExists FROM @preparedStatementIdx;
EXECUTE addIdxIfNotExists;
DEALLOCATE PREPARE addIdxIfNotExists;
