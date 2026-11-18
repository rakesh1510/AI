-- Phase 7 OPTIONAL triggers: DEFENCE IN DEPTH for the cross-table rules that CHECK constraints cannot express.
-- The PHP service layer is the PRIMARY enforcement of these rules and must enforce them whether or not this file is applied.
-- Feature detection: the installer tries this file and treats failure as non-fatal. Typical denials: error 1142 (no TRIGGER privilege),
-- error 1419 (binary logging on and no SUPER and log_bin_trust_function_creators = 0), error 1227. When it is skipped the schema still works,
-- and the service layer alone guards: a verified row has at least one source, the reviewer holds the verifier role, the review row matches the target,
-- verified rows stay immutable, release parents are earlier, and uni_review and uni_audit_log stay append-only (also restrict by GRANT, no UPDATE or DELETE).
-- Idempotent: every trigger is DROP TRIGGER IF EXISTS then CREATE. Rollback drops the tables, which drops these triggers with them.
-- Run with the mysql client (it understands DELIMITER) or any runner that sends each CREATE TRIGGER as one statement.
-- All errors are SQLSTATE 45000 with a message beginning 'uni:'.

DROP TRIGGER IF EXISTS trg_uni_entity_bi;
DROP TRIGGER IF EXISTS trg_uni_entity_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_entity_bi BEFORE INSERT ON uni_entity FOR EACH ROW
BEGIN
    IF NEW.state NOT IN ('proposed','in_review') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: rows must be inserted as proposed or in_review';
    END IF;
    IF NEW.supersedes_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM uni_entity p WHERE p.id = NEW.supersedes_id AND p.lineage_key = NEW.lineage_key AND p.version_no = NEW.version_no - 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: supersedes_id must be the previous version of the same lineage';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_uni_entity_bu BEFORE UPDATE ON uni_entity FOR EACH ROW
BEGIN
    IF NOT (NEW.proposed_by <=> OLD.proposed_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: proposed_by is immutable';
    END IF;
    IF NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: origin is immutable';
    END IF;
    IF OLD.state = 'verified' AND NEW.state = 'superseded' AND (SELECT COUNT(*) FROM uni_entity s WHERE s.supersedes_id = OLD.id AND s.state = 'verified') < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row can only be superseded by a verified successor version';
    END IF;
    IF OLD.state = 'superseded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: superseded rows are immutable';
    END IF;
    IF NEW.supersedes_id <=> OLD.id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a row cannot supersede itself';
    END IF;
    IF OLD.state = 'verified' THEN
        IF (NEW.state <> 'superseded' AND NEW.state <> 'verified') OR (NEW.state = 'verified' AND NEW.is_current < OLD.is_current)
          OR NOT (CAST(NEW.entity_type AS BINARY) <=> CAST(OLD.entity_type AS BINARY))
          OR NOT (CAST(NEW.slug AS BINARY) <=> CAST(OLD.slug AS BINARY))
          OR NOT (CAST(NEW.name AS BINARY) <=> CAST(OLD.name AS BINARY))
          OR NOT (CAST(NEW.summary AS BINARY) <=> CAST(OLD.summary AS BINARY))
          OR NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY))
          OR NOT (NEW.proposed_by <=> OLD.proposed_by)
          OR NOT (NEW.proposed_at <=> OLD.proposed_at)
          OR NOT (NEW.verified_by <=> OLD.verified_by)
          OR NOT (NEW.verified_at <=> OLD.verified_at)
          OR NOT (NEW.review_id <=> OLD.review_id)
          OR NOT (CAST(NEW.lineage_key AS BINARY) <=> CAST(OLD.lineage_key AS BINARY))
          OR NOT (NEW.version_no <=> OLD.version_no)
          OR NOT (NEW.supersedes_id <=> OLD.supersedes_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified rows are immutable (only supersede is allowed)';
        END IF;
    END IF;
    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        IF OLD.state NOT IN ('proposed','in_review') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: only proposed or in_review rows can be verified';
        END IF;
        IF (SELECT COUNT(*) FROM crm_users u WHERE u.id = NEW.verified_by AND u.role = 'verifier' AND u.active = 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified_by must be an active user with the verifier role';
        END IF;
        IF (SELECT COUNT(*) FROM uni_review r WHERE r.id = NEW.review_id AND r.target_type = 'entity' AND r.target_id = OLD.id
              AND r.decision = 'accept' AND r.reviewer_id = NEW.verified_by AND r.proposed_by = NEW.proposed_by) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: review_id must be an accept review of this row by verified_by';
        END IF;
        IF (SELECT COUNT(*) FROM uni_entity_source WHERE entity_id = OLD.id) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: cannot verify without at least one source';
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_event_bi;
DROP TRIGGER IF EXISTS trg_uni_event_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_event_bi BEFORE INSERT ON uni_event FOR EACH ROW
BEGIN
    IF NEW.state NOT IN ('proposed','in_review') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: rows must be inserted as proposed or in_review';
    END IF;
    IF NEW.supersedes_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM uni_event p WHERE p.id = NEW.supersedes_id AND p.lineage_key = NEW.lineage_key AND p.version_no = NEW.version_no - 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: supersedes_id must be the previous version of the same lineage';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_uni_event_bu BEFORE UPDATE ON uni_event FOR EACH ROW
BEGIN
    IF NOT (NEW.proposed_by <=> OLD.proposed_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: proposed_by is immutable';
    END IF;
    IF NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: origin is immutable';
    END IF;
    IF OLD.state = 'verified' AND NEW.state = 'superseded' AND (SELECT COUNT(*) FROM uni_event s WHERE s.supersedes_id = OLD.id AND s.state = 'verified') < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row can only be superseded by a verified successor version';
    END IF;
    IF OLD.state = 'superseded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: superseded rows are immutable';
    END IF;
    IF NEW.supersedes_id <=> OLD.id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a row cannot supersede itself';
    END IF;
    IF OLD.state = 'verified' THEN
        IF (NEW.state <> 'superseded' AND NEW.state <> 'verified') OR (NEW.state = 'verified' AND NEW.is_current < OLD.is_current)
          OR NOT (NEW.entity_id <=> OLD.entity_id)
          OR NOT (CAST(NEW.event_type AS BINARY) <=> CAST(OLD.event_type AS BINARY))
          OR NOT (NEW.event_date <=> OLD.event_date)
          OR NOT (CAST(NEW.date_precision AS BINARY) <=> CAST(OLD.date_precision AS BINARY))
          OR NOT (CAST(NEW.date_kind AS BINARY) <=> CAST(OLD.date_kind AS BINARY))
          OR NOT (CAST(NEW.title AS BINARY) <=> CAST(OLD.title AS BINARY))
          OR NOT (CAST(NEW.summary AS BINARY) <=> CAST(OLD.summary AS BINARY))
          OR NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY))
          OR NOT (NEW.proposed_by <=> OLD.proposed_by)
          OR NOT (NEW.proposed_at <=> OLD.proposed_at)
          OR NOT (NEW.verified_by <=> OLD.verified_by)
          OR NOT (NEW.verified_at <=> OLD.verified_at)
          OR NOT (NEW.review_id <=> OLD.review_id)
          OR NOT (CAST(NEW.lineage_key AS BINARY) <=> CAST(OLD.lineage_key AS BINARY))
          OR NOT (NEW.version_no <=> OLD.version_no)
          OR NOT (NEW.supersedes_id <=> OLD.supersedes_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified rows are immutable (only supersede is allowed)';
        END IF;
    END IF;
    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        IF OLD.state NOT IN ('proposed','in_review') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: only proposed or in_review rows can be verified';
        END IF;
        IF (SELECT COUNT(*) FROM crm_users u WHERE u.id = NEW.verified_by AND u.role = 'verifier' AND u.active = 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified_by must be an active user with the verifier role';
        END IF;
        IF (SELECT COUNT(*) FROM uni_review r WHERE r.id = NEW.review_id AND r.target_type = 'event' AND r.target_id = OLD.id
              AND r.decision = 'accept' AND r.reviewer_id = NEW.verified_by AND r.proposed_by = NEW.proposed_by) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: review_id must be an accept review of this row by verified_by';
        END IF;
        IF (SELECT COUNT(*) FROM uni_event_source WHERE event_id = OLD.id) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: cannot verify without at least one source';
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_edge_bi;
DROP TRIGGER IF EXISTS trg_uni_edge_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_edge_bi BEFORE INSERT ON uni_edge FOR EACH ROW
BEGIN
    IF NEW.state NOT IN ('proposed','in_review') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: rows must be inserted as proposed or in_review';
    END IF;
    IF NEW.supersedes_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM uni_edge p WHERE p.id = NEW.supersedes_id AND p.lineage_key = NEW.lineage_key AND p.version_no = NEW.version_no - 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: supersedes_id must be the previous version of the same lineage';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_uni_edge_bu BEFORE UPDATE ON uni_edge FOR EACH ROW
BEGIN
    IF NOT (NEW.proposed_by <=> OLD.proposed_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: proposed_by is immutable';
    END IF;
    IF NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: origin is immutable';
    END IF;
    IF OLD.state = 'verified' AND NEW.state = 'superseded' AND (SELECT COUNT(*) FROM uni_edge s WHERE s.supersedes_id = OLD.id AND s.state = 'verified') < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row can only be superseded by a verified successor version';
    END IF;
    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        IF (SELECT COUNT(*) FROM uni_entity e WHERE e.id IN (NEW.from_entity_id, NEW.to_entity_id) AND e.state = 'verified') < 2 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: both endpoints of an edge must be verified entities first';
        END IF;
    END IF;
    IF OLD.state = 'superseded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: superseded rows are immutable';
    END IF;
    IF NEW.supersedes_id <=> OLD.id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a row cannot supersede itself';
    END IF;
    IF OLD.state = 'verified' THEN
        IF (NEW.state <> 'superseded' AND NEW.state <> 'verified') OR (NEW.state = 'verified' AND NEW.is_current < OLD.is_current)
          OR NOT (NEW.from_entity_id <=> OLD.from_entity_id)
          OR NOT (NEW.to_entity_id <=> OLD.to_entity_id)
          OR NOT (CAST(NEW.edge_type AS BINARY) <=> CAST(OLD.edge_type AS BINARY))
          OR NOT (NEW.valid_from <=> OLD.valid_from)
          OR NOT (NEW.valid_to <=> OLD.valid_to)
          OR NOT (CAST(NEW.notes AS BINARY) <=> CAST(OLD.notes AS BINARY))
          OR NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY))
          OR NOT (NEW.proposed_by <=> OLD.proposed_by)
          OR NOT (NEW.proposed_at <=> OLD.proposed_at)
          OR NOT (NEW.verified_by <=> OLD.verified_by)
          OR NOT (NEW.verified_at <=> OLD.verified_at)
          OR NOT (NEW.review_id <=> OLD.review_id)
          OR NOT (CAST(NEW.lineage_key AS BINARY) <=> CAST(OLD.lineage_key AS BINARY))
          OR NOT (NEW.version_no <=> OLD.version_no)
          OR NOT (NEW.supersedes_id <=> OLD.supersedes_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified rows are immutable (only supersede is allowed)';
        END IF;
    END IF;
    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        IF OLD.state NOT IN ('proposed','in_review') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: only proposed or in_review rows can be verified';
        END IF;
        IF (SELECT COUNT(*) FROM crm_users u WHERE u.id = NEW.verified_by AND u.role = 'verifier' AND u.active = 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified_by must be an active user with the verifier role';
        END IF;
        IF (SELECT COUNT(*) FROM uni_review r WHERE r.id = NEW.review_id AND r.target_type = 'edge' AND r.target_id = OLD.id
              AND r.decision = 'accept' AND r.reviewer_id = NEW.verified_by AND r.proposed_by = NEW.proposed_by) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: review_id must be an accept review of this row by verified_by';
        END IF;
        IF (SELECT COUNT(*) FROM uni_edge_source WHERE edge_id = OLD.id) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: cannot verify without at least one source';
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_release_bi;
DROP TRIGGER IF EXISTS trg_uni_release_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_release_bi BEFORE INSERT ON uni_release FOR EACH ROW
BEGIN
    IF NEW.state NOT IN ('proposed','in_review') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: rows must be inserted as proposed or in_review';
    END IF;
    IF NEW.supersedes_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM uni_release p WHERE p.id = NEW.supersedes_id AND p.lineage_key = NEW.lineage_key AND p.version_no = NEW.version_no - 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: supersedes_id must be the previous version of the same lineage';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_uni_release_bu BEFORE UPDATE ON uni_release FOR EACH ROW
BEGIN
    IF NOT (NEW.proposed_by <=> OLD.proposed_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: proposed_by is immutable';
    END IF;
    IF NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: origin is immutable';
    END IF;
    IF OLD.state = 'superseded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: superseded rows are immutable';
    END IF;
    IF NEW.supersedes_id <=> OLD.id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a row cannot supersede itself';
    END IF;
    IF OLD.state = 'verified' THEN
        IF NEW.state <> 'superseded'
          OR NOT (NEW.entity_id <=> OLD.entity_id)
          OR NOT (NEW.event_id <=> OLD.event_id)
          OR NOT (CAST(NEW.version_label AS BINARY) <=> CAST(OLD.version_label AS BINARY))
          OR NOT (NEW.release_date <=> OLD.release_date)
          OR NOT (CAST(NEW.date_precision AS BINARY) <=> CAST(OLD.date_precision AS BINARY))
          OR NOT (NEW.source_id <=> OLD.source_id)
          OR NOT (CAST(NEW.change_set AS BINARY) <=> CAST(OLD.change_set AS BINARY))
          OR NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY))
          OR NOT (NEW.proposed_by <=> OLD.proposed_by)
          OR NOT (NEW.proposed_at <=> OLD.proposed_at)
          OR NOT (NEW.verified_by <=> OLD.verified_by)
          OR NOT (NEW.verified_at <=> OLD.verified_at)
          OR NOT (NEW.review_id <=> OLD.review_id)
          OR NOT (CAST(NEW.lineage_key AS BINARY) <=> CAST(OLD.lineage_key AS BINARY))
          OR NOT (NEW.version_no <=> OLD.version_no)
          OR NOT (NEW.supersedes_id <=> OLD.supersedes_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified rows are immutable (only supersede is allowed)';
        END IF;
    END IF;
    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        IF OLD.state NOT IN ('proposed','in_review') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: only proposed or in_review rows can be verified';
        END IF;
        IF (SELECT COUNT(*) FROM crm_users u WHERE u.id = NEW.verified_by AND u.role = 'verifier' AND u.active = 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified_by must be an active user with the verifier role';
        END IF;
        IF (SELECT COUNT(*) FROM uni_review r WHERE r.id = NEW.review_id AND r.target_type = 'release' AND r.target_id = OLD.id
              AND r.decision = 'accept' AND r.reviewer_id = NEW.verified_by AND r.proposed_by = NEW.proposed_by) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: review_id must be an accept review of this row by verified_by';
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_correction_bi;
DROP TRIGGER IF EXISTS trg_uni_correction_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_correction_bi BEFORE INSERT ON uni_correction FOR EACH ROW
BEGIN
    IF NEW.state NOT IN ('proposed','in_review') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: rows must be inserted as proposed or in_review';
    END IF;
END$$

CREATE TRIGGER trg_uni_correction_bu BEFORE UPDATE ON uni_correction FOR EACH ROW
BEGIN
    IF NOT (NEW.proposed_by <=> OLD.proposed_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: proposed_by is immutable';
    END IF;
    IF NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: origin is immutable';
    END IF;
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: decided corrections are immutable';
    END IF;
    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        IF (SELECT COUNT(*) FROM crm_users u WHERE u.id = NEW.verified_by AND u.role = 'verifier' AND u.active = 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified_by must be an active user with the verifier role';
        END IF;
        IF (SELECT COUNT(*) FROM uni_review r WHERE r.id = NEW.review_id AND r.target_type = 'correction' AND r.target_id = OLD.id
              AND r.decision = 'accept' AND r.reviewer_id = NEW.verified_by AND r.proposed_by = NEW.proposed_by) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: review_id must be an accept review of this row by verified_by';
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_event_source_bu;
DROP TRIGGER IF EXISTS trg_uni_event_source_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_event_source_bu BEFORE UPDATE ON uni_event_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_event x WHERE x.id IN (OLD.event_id, NEW.event_id) AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row are immutable';
    END IF;
END$$

CREATE TRIGGER trg_uni_event_source_bd BEFORE DELETE ON uni_event_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_event x WHERE x.id = OLD.event_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row cannot be removed';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_edge_source_bu;
DROP TRIGGER IF EXISTS trg_uni_edge_source_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_edge_source_bu BEFORE UPDATE ON uni_edge_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_edge x WHERE x.id IN (OLD.edge_id, NEW.edge_id) AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row are immutable';
    END IF;
END$$

CREATE TRIGGER trg_uni_edge_source_bd BEFORE DELETE ON uni_edge_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_edge x WHERE x.id = OLD.edge_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row cannot be removed';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_source_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_source_bu BEFORE UPDATE ON uni_source FOR EACH ROW
BEGIN
    IF NOT (NEW.created_by <=> OLD.created_by) OR NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) OR NOT (CAST(NEW.url AS BINARY) <=> CAST(OLD.url AS BINARY)) OR NOT (CAST(NEW.publisher AS BINARY) <=> CAST(OLD.publisher AS BINARY)) OR NOT (NEW.retrieved_at <=> OLD.retrieved_at) OR NOT (CAST(NEW.quote AS BINARY) <=> CAST(OLD.quote AS BINARY)) OR NOT (CAST(NEW.content_hash AS BINARY) <=> CAST(OLD.content_hash AS BINARY)) THEN
        IF EXISTS (SELECT 1 FROM uni_entity_source l JOIN uni_entity x ON x.id = l.entity_id WHERE l.source_id = OLD.id AND x.state = 'verified')
          OR EXISTS (SELECT 1 FROM uni_event_source l JOIN uni_event x ON x.id = l.event_id WHERE l.source_id = OLD.id AND x.state = 'verified')
          OR EXISTS (SELECT 1 FROM uni_edge_source l JOIN uni_edge x ON x.id = l.edge_id WHERE l.source_id = OLD.id AND x.state = 'verified')
          OR EXISTS (SELECT 1 FROM uni_release x WHERE x.source_id = OLD.id AND x.state = 'verified')
          OR EXISTS (SELECT 1 FROM uni_release_parent l JOIN uni_release x ON x.id = l.release_id WHERE l.source_id = OLD.id AND x.state = 'verified') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a source used by a verified row is immutable (add a new source instead)';
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_release_parent_bi;
DROP TRIGGER IF EXISTS trg_uni_release_parent_bu;
DROP TRIGGER IF EXISTS trg_uni_release_parent_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_release_parent_bi BEFORE INSERT ON uni_release_parent FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_release c WHERE c.id = NEW.release_id AND c.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: cannot add a parent to a verified release';
    END IF;
    IF (SELECT COUNT(*) FROM uni_release c JOIN uni_release p ON p.id = NEW.parent_release_id WHERE c.id = NEW.release_id AND p.release_date < c.release_date) < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a parent release must have a strictly earlier release_date';
    END IF;
END$$

CREATE TRIGGER trg_uni_release_parent_bu BEFORE UPDATE ON uni_release_parent FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: release parent links are immutable (delete and re-insert while the release is unverified)';
END$$

CREATE TRIGGER trg_uni_release_parent_bd BEFORE DELETE ON uni_release_parent FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_release c WHERE c.id = OLD.release_id AND c.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: cannot remove a parent from a verified release';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_review_bu;
DROP TRIGGER IF EXISTS trg_uni_review_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_review_bu BEFORE UPDATE ON uni_review FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: uni_review is append-only';
END$$

CREATE TRIGGER trg_uni_review_bd BEFORE DELETE ON uni_review FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: uni_review is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_audit_log_bu;
DROP TRIGGER IF EXISTS trg_uni_audit_log_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_audit_log_bu BEFORE UPDATE ON uni_audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: uni_audit_log is append-only';
END$$

CREATE TRIGGER trg_uni_audit_log_bd BEFORE DELETE ON uni_audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: uni_audit_log is append-only';
END$$
DELIMITER ;

-- Review hardening (QA agent findings): verified/superseded rows cannot be deleted (also blocks REPLACE INTO), and verified rows cannot gain source links.
DROP TRIGGER IF EXISTS trg_uni_entity_bd;
DROP TRIGGER IF EXISTS trg_uni_event_bd;
DROP TRIGGER IF EXISTS trg_uni_edge_bd;
DROP TRIGGER IF EXISTS trg_uni_release_bd;
DROP TRIGGER IF EXISTS trg_uni_correction_bd;
DROP TRIGGER IF EXISTS trg_uni_event_source_bi;
DROP TRIGGER IF EXISTS trg_uni_edge_source_bi;
DELIMITER $$
CREATE TRIGGER trg_uni_entity_bd BEFORE DELETE ON uni_entity FOR EACH ROW
BEGIN
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified or superseded rows cannot be deleted';
    END IF;
END$$

CREATE TRIGGER trg_uni_event_bd BEFORE DELETE ON uni_event FOR EACH ROW
BEGIN
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified or superseded rows cannot be deleted';
    END IF;
END$$

CREATE TRIGGER trg_uni_edge_bd BEFORE DELETE ON uni_edge FOR EACH ROW
BEGIN
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified or superseded rows cannot be deleted';
    END IF;
END$$

CREATE TRIGGER trg_uni_release_bd BEFORE DELETE ON uni_release FOR EACH ROW
BEGIN
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified or superseded rows cannot be deleted';
    END IF;
END$$

CREATE TRIGGER trg_uni_correction_bd BEFORE DELETE ON uni_correction FOR EACH ROW
BEGIN
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified or superseded rows cannot be deleted';
    END IF;
END$$

CREATE TRIGGER trg_uni_event_source_bi BEFORE INSERT ON uni_event_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_event x WHERE x.id = NEW.event_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row cannot gain source links (supersede it with a new version)';
    END IF;
END$$

CREATE TRIGGER trg_uni_edge_source_bi BEFORE INSERT ON uni_edge_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_edge x WHERE x.id = NEW.edge_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row cannot gain source links (supersede it with a new version)';
    END IF;
END$$

DELIMITER ;

-- Entity source links follow the same rules as event and edge source links.
DROP TRIGGER IF EXISTS trg_uni_entity_source_bi;
DROP TRIGGER IF EXISTS trg_uni_entity_source_bu;
DROP TRIGGER IF EXISTS trg_uni_entity_source_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_entity_source_bi BEFORE INSERT ON uni_entity_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_entity x WHERE x.id = NEW.entity_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row cannot gain source links (supersede it with a new version)';
    END IF;
END$$

CREATE TRIGGER trg_uni_entity_source_bu BEFORE UPDATE ON uni_entity_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_entity x WHERE x.id IN (OLD.entity_id, NEW.entity_id) AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row are immutable';
    END IF;
END$$

CREATE TRIGGER trg_uni_entity_source_bd BEFORE DELETE ON uni_entity_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_entity x WHERE x.id = OLD.entity_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row cannot be removed';
    END IF;
END$$
DELIMITER ;
