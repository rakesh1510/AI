-- Phase 7E rollback: removes only the affiliate tables (tools, analytics and the finance tables are untouched; partner, link and commission records are lost).
DROP TABLE IF EXISTS aff_commission_event;
DROP TABLE IF EXISTS aff_commission;
DROP TABLE IF EXISTS aff_link_history;
DROP TABLE IF EXISTS aff_link;
DROP TABLE IF EXISTS aff_partner_history;
DROP TABLE IF EXISTS aff_partner;
DROP TABLE IF EXISTS aff_network;
