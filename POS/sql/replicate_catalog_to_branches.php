<?php
/**
 * Replicate the product catalogue to every company branch that is missing it.
 *
 *   php POS/sql/replicate_catalog_to_branches.php            # dry run (shows what it would do)
 *   php POS/sql/replicate_catalog_to_branches.php --apply    # actually insert
 *
 * - Template branch  = the branch that currently has the most products.
 * - Target branches  = every branch from Landing Page/php/branch_list.php that
 *                      is missing one or more catalogue SKUs.
 * - New rows copy sku / name / category / brand / part_no / uom / description /
 *   price from the template; stock starts at 0 (a fresh branch has received
 *   nothing yet). Existing (sku, branch) rows are left untouched, so it is safe
 *   to re-run.
 */

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/Landing Page/php/db.php';          // $conn
require $ROOT . '/Landing Page/php/branch_list.php'; // all_branches(), l8_upper()

$APPLY    = in_array('--apply', $argv, true);
$NEW_STOCK = 0;

$conn->set_charset('utf8mb4');

/* ── Template branch: most products ── */
$row = $conn->query(
    "SELECT branch, COUNT(*) c FROM pos_products
     GROUP BY branch ORDER BY c DESC, branch ASC LIMIT 1"
)->fetch_row();

if (!$row || (int)$row[1] === 0) {
    fwrite(STDERR, "No products exist yet — nothing to replicate.\n");
    exit(1);
}
$template = $row[0];
echo "Template branch : {$template} ({$row[1]} products)\n";

/* ── Load the template catalogue ── */
$catalogue = [];
$res = $conn->prepare(
    "SELECT sku,name,category,brand,part_no,uom,description,price
     FROM pos_products WHERE branch = ?"
);
$res->bind_param('s', $template);
$res->execute();
$r = $res->get_result();
while ($p = $r->fetch_assoc()) $catalogue[$p['sku']] = $p;
$res->close();
echo "Catalogue size  : " . count($catalogue) . " SKUs\n\n";

/* ── Work out targets ── */
$targets = array_values(array_filter(
    all_branches($conn),
    fn($b) => l8_upper($b) !== l8_upper($template)
));

$ins = $conn->prepare(
    "INSERT INTO pos_products
       (sku,name,category,brand,part_no,uom,description,price,stock,branch,added_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,NULL)"
);

$grandTotal = 0;
foreach ($targets as $branch) {
    // SKUs this branch already has
    $have = [];
    $q = $conn->prepare("SELECT sku FROM pos_products WHERE branch = ?");
    $q->bind_param('s', $branch);
    $q->execute();
    $qr = $q->get_result();
    while ($x = $qr->fetch_row()) $have[$x[0]] = true;
    $q->close();

    $missing = array_diff_key($catalogue, $have);
    if (!$missing) {
        printf("  %-28s already complete (%d)\n", $branch, count($have));
        continue;
    }

    if ($APPLY) {
        $conn->begin_transaction();
        foreach ($missing as $p) {
            $ins->bind_param(
                'sssssssdis',
                $p['sku'], $p['name'], $p['category'], $p['brand'],
                $p['part_no'], $p['uom'], $p['description'], $p['price'],
                $NEW_STOCK, $branch
            );
            $ins->execute();
        }
        $conn->commit();
        printf("  %-28s + %d products inserted\n", $branch, count($missing));
    } else {
        printf("  %-28s would insert %d products\n", $branch, count($missing));
    }
    $grandTotal += count($missing);
}

$ins->close();
echo "\n" . ($APPLY ? "Done. Inserted " : "Dry run. Would insert ")
   . "$grandTotal product rows across " . count($targets) . " branches.\n";
if (!$APPLY) echo "Re-run with --apply to make the changes.\n";

$conn->close();
