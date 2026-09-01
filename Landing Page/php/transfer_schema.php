<?php
/**
 * Shared bootstrap for the inter-branch transfer flow tables.
 * Follows the same CREATE TABLE IF NOT EXISTS pattern as auth.php /
 * delivery_schema.php so the feature works on an existing database without a
 * manual migration step.
 * Canonical DDL also lives in POS/sql/migration_branch_transfer.sql.
 *
 * Flow: a branch (requesting_branch) asks a nearby branch (source_branch) for
 * stock. Source staff approve & ship (their stock drops), the requesting branch
 * confirms receipt (their stock rises). Or the source declines / the requester
 * cancels while still pending.
 */
function ensure_transfer_schema(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS branch_transfers (
            id                INT(11)      NOT NULL AUTO_INCREMENT,
            reference         VARCHAR(40)  NOT NULL,               -- TRF-XXXXXXXX-XXX
            requesting_branch VARCHAR(100) NOT NULL,               -- branch that needs stock (goods destination)
            source_branch     VARCHAR(100) NOT NULL,               -- branch asked to fulfil (goods origin)
            status            VARCHAR(20)  NOT NULL DEFAULT 'requested', -- requested|shipped|received|rejected|cancelled
            note              TEXT         DEFAULT NULL,           -- reason from the requester
            source_remarks    TEXT         DEFAULT NULL,           -- decline reason / packing note from source
            requested_by      INT(11)      DEFAULT NULL,
            requested_by_name VARCHAR(255) DEFAULT NULL,
            requested_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actioned_by       INT(11)      DEFAULT NULL,           -- source staff who shipped / declined
            actioned_by_name  VARCHAR(255) DEFAULT NULL,
            actioned_at       DATETIME     DEFAULT NULL,
            received_by       INT(11)      DEFAULT NULL,
            received_by_name  VARCHAR(255) DEFAULT NULL,
            received_at       DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY reference (reference),
            INDEX idx_source_status (source_branch, status),
            INDEX idx_requesting_status (requesting_branch, status),
            INDEX idx_requested_at (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS branch_transfer_items (
            id            INT(11)      NOT NULL AUTO_INCREMENT,
            transfer_id   INT(11)      NOT NULL,
            product_id    INT(11)      DEFAULT NULL,               -- source pos_products.id, if known
            sku           VARCHAR(50)  NOT NULL,
            name          VARCHAR(255) NOT NULL,
            category      VARCHAR(100) DEFAULT NULL,
            qty_requested INT(11)      NOT NULL,
            qty_shipped   INT(11)      DEFAULT NULL,               -- what source actually sent (set on approve)
            applied       TINYINT(1)   NOT NULL DEFAULT 0,         -- whether it was added to the requester's stock
            PRIMARY KEY (id),
            INDEX idx_transfer (transfer_id),
            CONSTRAINT fk_bti_transfer FOREIGN KEY (transfer_id)
                REFERENCES branch_transfers (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Optional per-branch region, used to sort/label "nearby" branches in the
    // cross-branch stock lookup. Populated by admins on Admin Console/transfers.php;
    // works fine (everything counts as "other branches") while unset.
    $conn->query("
        CREATE TABLE IF NOT EXISTS branch_directory (
            branch     VARCHAR(100) NOT NULL,
            region     VARCHAR(80)  DEFAULT NULL,
            city       VARCHAR(120) DEFAULT NULL,
            sort_order INT(11)      NOT NULL DEFAULT 0,
            is_active  TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (branch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Seed the directory from branches known elsewhere in the system. Only run
    // the (3-way UNION) scan when something is actually missing, so this stays
    // cheap on every POS page load — INSERT IGNORE never clobbers a set region.
    $have = (int)($conn->query("SELECT COUNT(*) FROM branch_directory")->fetch_row()[0] ?? 0);
    $need = (int)($conn->query("
        SELECT COUNT(*) FROM (
            SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci AS b FROM users
                WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
            UNION
            SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci FROM pos_products
                WHERE branch IS NOT NULL AND branch <> ''
        ) t WHERE b IS NOT NULL AND b <> ''
    ")->fetch_row()[0] ?? 0);

    if ($need > $have) $conn->query("
        INSERT IGNORE INTO branch_directory (branch)
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
        WHERE b IS NOT NULL AND b <> ''
    ");

    // Transfer audit rows stamp audit_trail.batch_id with the transfer reference
    // — make sure that column exists on databases that predate the delivery work.
    $col = $conn->query("SHOW COLUMNS FROM audit_trail LIKE 'batch_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE audit_trail
                      ADD COLUMN batch_id VARCHAR(40) NULL AFTER entity_name,
                      ADD INDEX idx_batch_id (batch_id)");
    }
}

/** TRF-XXXXXXXX-XXX reference, matching the DEL- delivery format. */
function transfer_reference(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = 'TRF-';
    for ($i = 0; $i < 8; $i++) $out .= $chars[random_int(0, 35)];
    $out .= '-';
    for ($i = 0; $i < 3; $i++) $out .= $chars[random_int(0, 35)];
    return $out;
}

/** Units of head-room kept back before stock counts as "surplus" for transfers. */
const TRANSFER_SURPLUS_BUFFER = 10;
