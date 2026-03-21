ALTER TABLE tool_votes
    ADD COLUMN visitor_id CHAR(32) NULL AFTER vote_type,
    ADD INDEX idx_tool_votes_ip (ip_address);

UPDATE tool_votes
SET visitor_id = MD5(CONCAT(COALESCE(ip_address, ''), ':', id))
WHERE visitor_id IS NULL OR visitor_id = '';

ALTER TABLE tool_votes
    DROP INDEX uniq_tool_vote_ip,
    ADD UNIQUE KEY uniq_tool_vote_visitor (tool_slug, visitor_id);
