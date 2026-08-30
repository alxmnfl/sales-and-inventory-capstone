<?php
/**
 * Shared bootstrap for the inventory-delivery flow tables.
 * Follows the same CREATE TABLE IF NOT EXISTS pattern used by auth.php so the
 * feature works on an existing database without a manual migration step.
 * Canonical DDL also lives in POS/sql/migration_inventory_delivery.sql.
 */
function ensure_delivery_schema(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS inventory_deliveries (
            id               INT(11)      NOT NULL AUTO_INCREMENT,
            reference        VARCHAR(40)  NOT NULL,
            branch           VARCHAR(100) NOT NULL,
            status           VARCHAR(20)  NOT NULL DEFAULT 'sent',
            note             TEXT         DEFAULT NULL,
            created_by       INT(11)      DEFAULT NULL,
            created_by_name  VARCHAR(255) DEFAULT NULL,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            received_by      INT(11)      DEFAULT NULL,
            received_by_name VARCHAR(255) DEFAULT NULL,
            received_at      DATETIME     DEFAULT NULL,
            staff_remarks    TEXT         DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY reference (reference),
            INDEX idx_branch_status (branch, status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS inventory_delivery_items (
            id           INT(11)      NOT NULL AUTO_INCREMENT,
            delivery_id  INT(11)      NOT NULL,
            product_id   INT(11)      DEFAULT NULL,
            sku          VARCHAR(50)  NOT NULL,
            name         VARCHAR(255) NOT NULL,
            category     VARCHAR(100) DEFAULT NULL,
            qty_sent     INT(11)      NOT NULL,
            qty_received INT(11)      DEFAULT NULL,
            applied      TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            INDEX idx_delivery (delivery_id),
            CONSTRAINT fk_idi_delivery FOREIGN KEY (delivery_id)
                REFERENCES inventory_deliveries (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Receiving a delivery stamps the audit row with the delivery reference in
    // audit_trail.batch_id — make sure that column exists on older databases.
    $col = $conn->query("SHOW COLUMNS FROM audit_trail LIKE 'batch_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE audit_trail
                      ADD COLUMN batch_id VARCHAR(40) NULL AFTER entity_name,
                      ADD INDEX idx_batch_id (batch_id)");
    }
}
