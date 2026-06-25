-- Lucky 8 Branch-Specific Products & Audit Trail Migration
-- Run this ONCE against lucky8_db after the initial setup.sql

USE lucky8_db;

-- 1. Add branch ownership and creator tracking to products
ALTER TABLE pos_products
  ADD COLUMN branch   VARCHAR(100) NOT NULL DEFAULT '' AFTER stock,
  ADD COLUMN added_by INT          DEFAULT NULL        AFTER branch;

-- 2. Change SKU uniqueness from global to per-branch
--    (same SKU can exist across different branches)
ALTER TABLE pos_products DROP INDEX sku;
ALTER TABLE pos_products ADD UNIQUE KEY sku_branch (sku, branch);

-- 3. Remove hardcoded default from pos_sales.branch so it must always come from session
ALTER TABLE pos_sales MODIFY COLUMN branch VARCHAR(100) NOT NULL DEFAULT '';

-- 4. Audit trail — logs product add/edit/delete and sale completions
CREATE TABLE IF NOT EXISTS audit_trail (
    id          INT          NOT NULL AUTO_INCREMENT,
    user_id     INT          NOT NULL,
    user_name   VARCHAR(255) NOT NULL,
    branch      VARCHAR(100) NOT NULL,
    action      VARCHAR(50)  NOT NULL,
    entity_type VARCHAR(50)  NOT NULL DEFAULT 'product',
    entity_id   INT          DEFAULT NULL,
    entity_name VARCHAR(255) DEFAULT NULL,
    details     TEXT         DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_branch     (branch),
    INDEX idx_action     (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
