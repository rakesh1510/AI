-- Phase 3: conversion journeys and consent-gated events. No IP addresses or user-agent strings are stored.
CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    lead_type VARCHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    company VARCHAR(190) NOT NULL DEFAULT '',
    website VARCHAR(255) NOT NULL DEFAULT '',
    budget VARCHAR(40) NOT NULL DEFAULT '',
    timeline VARCHAR(40) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    source_page VARCHAR(120) NOT NULL DEFAULT '',
    consent_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    ip_hash CHAR(16) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_leads_type_created (lead_type, created_at),
    KEY idx_leads_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscribers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    token CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    last_confirmation_sent_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    consent_text VARCHAR(255) NOT NULL DEFAULT '',
    source_page VARCHAR(120) NOT NULL DEFAULT '',
    ip_hash CHAR(16) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sub_email (email),
    UNIQUE KEY uniq_sub_token (token),
    KEY idx_sub_status (status),
    KEY idx_sub_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
