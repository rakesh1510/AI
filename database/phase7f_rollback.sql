-- Phase 7F rollback: removes only the advertising booking tables (CRM campaigns, proposals, contracts, revenue entries, finance records and tool placements are untouched; package settings, booking details and history are lost).
DROP TABLE IF EXISTS ad_advertiser_tool;
DROP TABLE IF EXISTS ad_advertiser;
DROP TABLE IF EXISTS ad_booking_history;
DROP TABLE IF EXISTS ad_booking;
DROP TABLE IF EXISTS ad_package;
