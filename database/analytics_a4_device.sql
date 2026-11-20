-- Analytics A4 (OPTIONAL, ADDITIVE): coarse device class on page views. Run by an administrator after review, on STAGING first; the application never runs it.
-- Stores one word: mobile | tablet | desktop (empty for bots and for Do-Not-Track / Global Privacy Control requests). The user-agent string is never stored.
-- Without this column everything keeps working: nothing about devices is written and the dashboard says "Device data is not collected on this server yet".
-- MySQL 8.0+: INSTANT add, no table rebuild and no long lock. Rollback: database/analytics_a4_device_rollback.sql
ALTER TABLE page_views ADD COLUMN device VARCHAR(10) NOT NULL DEFAULT '', ALGORITHM=INSTANT;
