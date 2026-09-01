-- Inter-branch transfer flow: a branch requests stock from a nearby branch,
-- the source branch's staff approve & ship it (their stock drops), and the
-- requesting branch confirms receipt (their stock rises). Either side can bail
-- out while the request is still pending (source declines / requester cancels).
--
-- These tables are also auto-created on first use by
-- Landing Page/php/transfer_schema.php (ensure_transfer_schema()).

USE lucky8_db;

CREATE TABLE IF NOT EXISTS `branch_transfers` (
  `id`                int(11)      NOT NULL AUTO_INCREMENT,
  `reference`         varchar(40)  NOT NULL,                -- TRF-XXXXXXXX-XXX
  `requesting_branch` varchar(100) NOT NULL,                -- needs stock (goods destination, UPPERCASE)
  `source_branch`     varchar(100) NOT NULL,                -- asked to fulfil (goods origin, UPPERCASE)
  `status`            varchar(20)  NOT NULL DEFAULT 'requested', -- requested|shipped|received|rejected|cancelled
  `note`              text         DEFAULT NULL,            -- reason from the requester
  `source_remarks`    text         DEFAULT NULL,            -- decline reason / packing note from source
  `requested_by`      int(11)      DEFAULT NULL,
  `requested_by_name` varchar(255) DEFAULT NULL,
  `requested_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actioned_by`       int(11)      DEFAULT NULL,            -- source staff who shipped / declined
  `actioned_by_name`  varchar(255) DEFAULT NULL,
  `actioned_at`       datetime     DEFAULT NULL,
  `received_by`       int(11)      DEFAULT NULL,
  `received_by_name`  varchar(255) DEFAULT NULL,
  `received_at`       datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  INDEX `idx_source_status` (`source_branch`, `status`),
  INDEX `idx_requesting_status` (`requesting_branch`, `status`),
  INDEX `idx_requested_at` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `branch_transfer_items` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `transfer_id`   int(11)      NOT NULL,
  `product_id`    int(11)      DEFAULT NULL,                -- source pos_products.id, if known
  `sku`           varchar(50)  NOT NULL,
  `name`          varchar(255) NOT NULL,
  `category`      varchar(100) DEFAULT NULL,
  `qty_requested` int(11)      NOT NULL,
  `qty_shipped`   int(11)      DEFAULT NULL,                -- what source actually sent (set on approve)
  `applied`       tinyint(1)   NOT NULL DEFAULT 0,          -- whether it was added to the requester's stock
  PRIMARY KEY (`id`),
  INDEX `idx_transfer` (`transfer_id`),
  CONSTRAINT `fk_bti_transfer` FOREIGN KEY (`transfer_id`)
    REFERENCES `branch_transfers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `branch_directory` (
  `branch`     varchar(100) NOT NULL,                       -- UPPERCASE branch name
  `region`     varchar(80)  DEFAULT NULL,                   -- e.g. "METRO MANILA", "CENTRAL LUZON"
  `city`       varchar(120) DEFAULT NULL,
  `sort_order` int(11)      NOT NULL DEFAULT 0,
  `is_active`  tinyint(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the directory from branches already known elsewhere (safe to re-run).
INSERT IGNORE INTO `branch_directory` (`branch`)
SELECT b FROM (
    SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci AS b FROM users
        WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
    UNION
    SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci FROM pos_products
        WHERE branch IS NOT NULL AND branch <> ''
    UNION
    SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci FROM pos_sales
        WHERE branch IS NOT NULL AND branch <> ''
) t
WHERE b IS NOT NULL AND b <> '';

-- audit_trail.batch_id is shared with the delivery flow; add it if missing.
-- ALTER TABLE `audit_trail`
--   ADD COLUMN `batch_id` varchar(40) NULL AFTER `entity_name`,
--   ADD INDEX `idx_batch_id` (`batch_id`);
