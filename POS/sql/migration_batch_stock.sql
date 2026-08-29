
ALTER TABLE audit_trail
  ADD COLUMN batch_id VARCHAR(40) NULL AFTER entity_name,
  ADD INDEX idx_batch_id (batch_id);
