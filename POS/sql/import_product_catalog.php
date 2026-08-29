<?php
/**
 * One-time import of the Lucky8 product master catalog into pos_products,
 * seeding a row per product for every branch (price/stock default to 0).
 *
 * Usage: php import_product_catalog.php [path\to\Lucky8_Product_Master_Organized.csv]
 */

require_once __DIR__ . '/../../Landing Page/php/db.php';

$csvPath = $argv[1] ?? 'C:\\Users\\rogel\\Downloads\\Lucky8_Product_Master_Organized.csv';

if (!is_readable($csvPath)) {
    fwrite(STDERR, "Cannot read CSV file: $csvPath\n");
    exit(1);
}

$branches = [];
$r = $conn->query("SELECT DISTINCT UPPER(branch) b FROM users WHERE branch IS NOT NULL AND branch != '' AND UPPER(branch) != 'ALL BRANCHES' ORDER BY b");
while ($row = $r->fetch_row()) {
    $branches[] = $row[0];
}
if (!$branches) {
    fwrite(STDERR, "No branches found in users table.\n");
    exit(1);
}

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh);
$col = array_flip($header);

$stmt = $conn->prepare(
    "INSERT INTO pos_products (sku, name, category, brand, part_no, uom, description, price, stock, branch, added_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NULL)
     ON DUPLICATE KEY UPDATE
       name = VALUES(name), category = VALUES(category), brand = VALUES(brand),
       part_no = VALUES(part_no), uom = VALUES(uom), description = VALUES(description)"
);
$stmt->bind_param('ssssssss', $sku, $name, $category, $brand, $partNo, $uom, $description, $branch);

$counts = array_fill_keys($branches, 0);
$productCount = 0;

while (($row = fgetcsv($fh)) !== false) {
    $sku         = trim($row[$col['product_id']]);
    $name        = trim($row[$col['product_name']]);
    $category    = trim($row[$col['category']]);
    $brand       = trim($row[$col['brand']]) ?: null;
    $partNo      = trim($row[$col['part_no']]) ?: null;
    $uom         = trim($row[$col['uom']]) ?: null;
    $description = trim($row[$col['description']]) ?: null;

    if ($sku === '') {
        continue;
    }
    $productCount++;

    foreach ($branches as $branch) {
        $stmt->execute();
        $counts[$branch]++;
    }
}
fclose($fh);
$stmt->close();

$auditStmt = $conn->prepare(
    "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
     VALUES (0, 'System Import', ?, 'BULK_IMPORT_PRODUCTS', 'product', NULL, ?, ?)"
);
foreach ($branches as $branch) {
    $entityName = 'Product Catalog';
    $details    = "Imported $productCount products from catalog file";
    $auditStmt->bind_param('sss', $branch, $entityName, $details);
    $auditStmt->execute();
}
$auditStmt->close();

echo "Products in source file: $productCount\n";
foreach ($counts as $branch => $count) {
    echo "  $branch: $count rows inserted/updated\n";
}
