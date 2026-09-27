<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'lucky8_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

/** Every branch that appears anywhere (staff roster, product catalogue, or
 *  sales history) so branches with no staff/products/sales yet still show up.
 *  (branch columns differ in collation between tables, hence the COLLATE.)
 *  Pass $excludeAllBranches = false where "ALL BRANCHES" is a valid value
 *  to list (e.g. the Users page, assigning an administrator). */
function get_all_branches(mysqli $conn, bool $excludeAllBranches = true): array {
    $usersWhere = "WHERE branch IS NOT NULL AND branch <> ''"
        . ($excludeAllBranches ? " AND UPPER(branch) <> 'ALL BRANCHES'" : '');

    $branches = [];
    $r = $conn->query("
        SELECT DISTINCT b FROM (
            SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci AS b FROM users
                $usersWhere
            UNION
            SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_products
                WHERE branch IS NOT NULL AND branch <> ''
            UNION
            SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_sales
                WHERE branch IS NOT NULL AND branch <> ''
        ) t
        ORDER BY b
    ");
    if ($r) {
        while ($row = $r->fetch_row()) {
            $branches[] = $row[0];
        }
    }
    return $branches;
}

/** Renders the shared prev/numbers/next pagination widget (used by Sales,
 *  Movement, Audit Trail, Reports). $urlFor(int $page): string builds the
 *  href for a given page number (querystring only, e.g. "?branch=X&pg=2");
 *  $anchor is the id of the table to scroll back to (no leading '#'). */
function render_pagination(int $current, int $total, callable $urlFor, string $anchor): void {
    if ($total <= 1) return;

    $prev = max(1, $current - 1);
    $next = min($total, $current + 1);
    echo '<div class="pagination">';
    printf(
        '<a href="%s#%s" class="pg-btn%s"><i class="fa-solid fa-chevron-left"></i></a>',
        htmlspecialchars($urlFor($prev)), htmlspecialchars($anchor), $current <= 1 ? ' disabled' : ''
    );
    for ($p = max(1, $current - 2); $p <= min($total, $current + 2); $p++) {
        printf(
            '<a href="%s#%s" class="pg-btn%s">%d</a>',
            htmlspecialchars($urlFor($p)), htmlspecialchars($anchor), $p === $current ? ' active' : '', $p
        );
    }
    printf(
        '<a href="%s#%s" class="pg-btn%s"><i class="fa-solid fa-chevron-right"></i></a>',
        htmlspecialchars($urlFor($next)), htmlspecialchars($anchor), $current >= $total ? ' disabled' : ''
    );
    echo '</div>';
}
