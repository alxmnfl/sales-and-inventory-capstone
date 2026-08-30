-- Inventory delivery flow: admin sends a delivery document to a branch,
-- branch staff reviews it against the physical delivery and confirms receipt,
-- and the system then adds the received quantities to that branch's stock.
--
-- These tables are also auto-created on first use by
-- Landing Page/php/delivery_schema.php (ensure_delivery_schema()).

USE lucky8_db;

CREATE TABLE IF NOT EXISTS `inventory_deliveries` (
  `id`               int(11)      NOT NULL AUTO_INCREMENT,
  `reference`        varchar(40)  NOT NULL,               -- DEL-XXXXXXXX-XXX
  `branch`           varchar(100) NOT NULL,               -- destination branch (UPPERCASE)
  `status`           varchar(20)  NOT NULL DEFAULT 'sent',-- sent | received | disputed | cancelled
  `note`             text         DEFAULT NULL,           -- admin packing-slip note
  `created_by`       int(11)      DEFAULT NULL,
  `created_by_name`  varchar(255) DEFAULT NULL,
  `created_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_by`      int(11)      DEFAULT NULL,
  `received_by_name` varchar(255) DEFAULT NULL,
  `received_at`      datetime     DEFAULT NULL,
  `staff_remarks`    text         DEFAULT NULL,           -- discrepancy notes when disputed
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  INDEX `idx_branch_status` (`branch`, `status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_delivery_items` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `delivery_id`  int(11)      NOT NULL,
  `product_id`   int(11)      DEFAULT NULL,               -- pos_products.id at destination branch, if known
  `sku`          varchar(50)  NOT NULL,
  `name`         varchar(255) NOT NULL,
  `category`     varchar(100) DEFAULT NULL,
  `qty_sent`     int(11)      NOT NULL,
  `qty_received` int(11)      DEFAULT NULL,               -- filled on confirmation
  `applied`      tinyint(1)   NOT NULL DEFAULT 0,         -- whether stock was added
  PRIMARY KEY (`id`),
  INDEX `idx_delivery` (`delivery_id`),
  CONSTRAINT `fk_idi_delivery` FOREIGN KEY (`delivery_id`)
    REFERENCES `inventory_deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
