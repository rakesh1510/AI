-- Phase 7 facts OPTIONAL triggers: DEFENCE IN DEPTH for the cross-table rules of uni_fact that CHECK constraints cannot express. Same rules as uni_event.
-- The PHP service layer (uni_core.php) is the PRIMARY enforcement and must enforce them whether or not this file is applied. Requires phase7_facts.sql.
-- Feature detection: the installer tries this file and treats failure as non-fatal (error 1142 no TRIGGER privilege, 1419 binary logging without SUPER, 1227).
-- Enforced here: rows are inserted as proposed or in_review; supersedes_id is the previous version of the same lineage; proposed_by and origin are immutable;
-- verified rows are immutable except to become superseded by a VERIFIED successor and are never deleted (this also blocks REPLACE INTO); superseded rows are immutable;
-- a verified fact needs an active verifier, an accept review of this fact by that verifier and at least one source link; fact source links are frozen once verified;
-- uni_fact_review is append-only; a source used by a verified fact cannot be edited (a second trigger on uni_source, so phase7_triggers.sql is not redefined).
-- Idempotent: every trigger is DROP TRIGGER IF EXISTS then CREATE. Rollback (phase7_facts_rollback.sql) drops the tables and the one trigger on uni_source.
-- All errors are SQLSTATE 45000 with a message beginning 'uni:'.

DROP TRIGGER IF EXISTS trg_uni_fact_bi;
DROP TRIGGER IF EXISTS trg_uni_fact_bu;
DROP TRIGGER IF EXISTS trg_uni_fact_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_fact_bi BEFORE INSERT ON uni_fact FOR EACH ROW
BEGIN
    IF NEW.state NOT IN ('proposed','in_review') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: rows must be inserted as proposed or in_review';
    END IF;
    IF NEW.supersedes_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM uni_fact p WHERE p.id = NEW.supersedes_id AND p.lineage_key = NEW.lineage_key AND p.version_no = NEW.version_no - 1) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: supersedes_id must be the previous version of the same lineage';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_uni_fact_bu BEFORE UPDATE ON uni_fact FOR EACH ROW
BEGIN
    IF NOT (NEW.proposed_by <=> OLD.proposed_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: proposed_by is immutable';
    END IF;
    IF NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: origin is immutable';
    END IF;
    IF OLD.state = 'verified' AND NEW.state = 'superseded' AND (SELECT COUNT(*) FROM uni_fact s WHERE s.supersedes_id = OLD.id AND s.state = 'verified') < 1 THEN
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
          OR NOT (NEW.release_id <=> OLD.release_id)
          OR NOT (CAST(NEW.fact_type AS BINARY) <=> CAST(OLD.fact_type AS BINARY))
          OR NOT (CAST(NEW.fact_key AS BINARY) <=> CAST(OLD.fact_key AS BINARY))
          OR NOT (CAST(NEW.value_text AS BINARY) <=> CAST(OLD.value_text AS BINARY))
          OR NOT (NEW.value_num <=> OLD.value_num)
          OR NOT (CAST(NEW.unit AS BINARY) <=> CAST(OLD.unit AS BINARY))
          OR NOT (CAST(NEW.benchmark_name AS BINARY) <=> CAST(OLD.benchmark_name AS BINARY))
          OR NOT (CAST(NEW.benchmark_metric AS BINARY) <=> CAST(OLD.benchmark_metric AS BINARY))
          OR NOT (CAST(NEW.benchmark_version AS BINARY) <=> CAST(OLD.benchmark_version AS BINARY))
          OR NOT (CAST(NEW.conditions AS BINARY) <=> CAST(OLD.conditions AS BINARY))
          OR NOT (NEW.observed_on <=> OLD.observed_on)
          OR NOT (CAST(NEW.date_precision AS BINARY) <=> CAST(OLD.date_precision AS BINARY))
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
        IF (SELECT COUNT(*) FROM uni_fact_review r WHERE r.id = NEW.review_id AND r.target_type = 'fact' AND r.target_id = OLD.id
              AND r.decision = 'accept' AND r.reviewer_id = NEW.verified_by AND r.proposed_by = NEW.proposed_by) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: review_id must be an accept review of this row by verified_by';
        END IF;
        IF (SELECT COUNT(*) FROM uni_fact_source WHERE fact_id = OLD.id) < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: cannot verify without at least one source';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_uni_fact_bd BEFORE DELETE ON uni_fact FOR EACH ROW
BEGIN
    IF OLD.state IN ('verified','superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: verified or superseded rows cannot be deleted';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_fact_source_bi;
DROP TRIGGER IF EXISTS trg_uni_fact_source_bu;
DROP TRIGGER IF EXISTS trg_uni_fact_source_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_fact_source_bi BEFORE INSERT ON uni_fact_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_fact x WHERE x.id = NEW.fact_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a verified row cannot gain source links (supersede it with a new version)';
    END IF;
END$$

CREATE TRIGGER trg_uni_fact_source_bu BEFORE UPDATE ON uni_fact_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_fact x WHERE x.id IN (OLD.fact_id, NEW.fact_id) AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row are immutable';
    END IF;
END$$

CREATE TRIGGER trg_uni_fact_source_bd BEFORE DELETE ON uni_fact_source FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM uni_fact x WHERE x.id = OLD.fact_id AND x.state = 'verified') > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: source links of a verified row cannot be removed';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_uni_fact_review_bu;
DROP TRIGGER IF EXISTS trg_uni_fact_review_bd;
DELIMITER $$
CREATE TRIGGER trg_uni_fact_review_bu BEFORE UPDATE ON uni_fact_review FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: uni_fact_review is append-only';
END$$

CREATE TRIGGER trg_uni_fact_review_bd BEFORE DELETE ON uni_fact_review FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: uni_fact_review is append-only';
END$$
DELIMITER ;

-- A source used by a verified fact is immutable. A second BEFORE UPDATE trigger on uni_source (the original one in phase7_triggers.sql stays untouched).
DROP TRIGGER IF EXISTS trg_uni_fact_srcguard_bu;
DELIMITER $$
CREATE TRIGGER trg_uni_fact_srcguard_bu BEFORE UPDATE ON uni_source FOR EACH ROW
BEGIN
    IF NOT (NEW.created_by <=> OLD.created_by) OR NOT (CAST(NEW.origin AS BINARY) <=> CAST(OLD.origin AS BINARY)) OR NOT (CAST(NEW.url AS BINARY) <=> CAST(OLD.url AS BINARY))
       OR NOT (CAST(NEW.publisher AS BINARY) <=> CAST(OLD.publisher AS BINARY)) OR NOT (NEW.retrieved_at <=> OLD.retrieved_at) OR NOT (CAST(NEW.quote AS BINARY) <=> CAST(OLD.quote AS BINARY))
       OR NOT (NEW.content_hash <=> OLD.content_hash) THEN
        IF EXISTS (SELECT 1 FROM uni_fact_source l JOIN uni_fact x ON x.id = l.fact_id WHERE l.source_id = OLD.id AND x.state = 'verified') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uni: a source used by a verified row is immutable (add a new source instead)';
        END IF;
    END IF;
END$$
DELIMITER ;
