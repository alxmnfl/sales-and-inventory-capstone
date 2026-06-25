USE lucky8_db;

CREATE TABLE IF NOT EXISTS `pos_products` (
  `id`       int(11)      NOT NULL AUTO_INCREMENT,
  `sku`      varchar(50)  NOT NULL,
  `name`     varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `price`    decimal(10,2) NOT NULL,
  `stock`    int(11)      NOT NULL DEFAULT 0,
  `branch`   varchar(100) NOT NULL DEFAULT '',
  `added_by` int(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku_branch` (`sku`, `branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pos_sales` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(50)  NOT NULL,
  `cashier`        varchar(255) NOT NULL,
  `payment_method` varchar(50)  NOT NULL DEFAULT 'CASH',
  `subtotal`       decimal(10,2) NOT NULL,
  `vat`            decimal(10,2) NOT NULL,
  `total`          decimal(10,2) NOT NULL,
  `cash_tendered`  decimal(10,2) DEFAULT NULL,
  `branch`         varchar(100) NOT NULL DEFAULT '',
  `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pos_sale_items` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `sale_id`      int(11)      NOT NULL,
  `product_id`   int(11)      NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `sku`          varchar(50)  NOT NULL,
  `quantity`     int(11)      NOT NULL,
  `unit_price`   decimal(10,2) NOT NULL,
  `total_price`  decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`) REFERENCES `pos_sales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_trail` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)      NOT NULL,
  `user_name`   varchar(255) NOT NULL,
  `branch`      varchar(100) NOT NULL,
  `action`      varchar(50)  NOT NULL,
  `entity_type` varchar(50)  NOT NULL DEFAULT 'product',
  `entity_id`   int(11)      DEFAULT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `details`     text         DEFAULT NULL,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_branch`     (`branch`),
  INDEX `idx_action`     (`action`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
