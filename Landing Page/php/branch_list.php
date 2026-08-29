<?php
/**
 * Canonical company branch list — single source of truth for branch names.
 * Keep in sync with the picker in Landing Page/src/login.js.
 *
 * The stored `branch` string (users / pos_products / pos_sales / audit_trail) is
 * one of these names; the app compares branches case-insensitively.
 */

$LUCKY8_BRANCHES = [
    'Lucky 8 — Dinalupihan',
    'Lucky 8 — Las Piñas City',
    'Lucky 8 — Viscaya',
    'Lucky 8 — Bambang',
    'Lucky 8 — Bagabag',
    'Win Flex — Baguio',
    'LIMA — Dasmariñas',
    'SMDA — Sta. Rosa',
    'Win Flex — Castilla',
    "Matthew's — San Pablo",
    'Win Flex — Naga',
    'Win Flex — Sucat',
    'Win Flex — Bañag',
    'Win Flex — Ligao',
    'Win Flex — San Pablo',
    "Matthew's — Lipa",
    'Crown Flex — Molino',
    'Win Flex — Castellejos',
];

/** Upper-case that also handles ñ / accented characters. */
function l8_upper($s) {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

/**
 * Every branch to offer in an admin picker: the full company list above PLUS any
 * other branch already saved in the database (so a value is never lost), with
 * "ALL BRANCHES" / blanks removed, upper-cased, de-duplicated and naturally
 * sorted. Works even before any users, products or sales exist.
 *
 * @param  mysqli|null $conn
 * @return string[]
 */
function all_branches($conn) {
    global $LUCKY8_BRANCHES;

    $set = [];
    $add = function ($name) use (&$set) {
        $u = l8_upper(trim((string)$name));
        if ($u === '' || $u === 'ALL BRANCHES') return;
        // Skip charset-corruption artefacts (a bad old write turned "—"/"ñ" into "?").
        if (strpos($u, '?') !== false || !mb_check_encoding($u, 'UTF-8')) return;
        $set[$u] = true;
    };

    foreach ($LUCKY8_BRANCHES as $b) {
        $add($b);
    }

    if ($conn instanceof mysqli) {
        foreach (['users', 'pos_products', 'pos_sales', 'audit_trail'] as $table) {
            // @ — a table that doesn't exist yet just contributes nothing.
            $res = @$conn->query("SELECT DISTINCT branch FROM `$table` WHERE branch IS NOT NULL AND branch <> ''");
            if ($res) {
                while ($row = $res->fetch_row()) {
                    $add($row[0]);
                }
                $res->free();
            }
        }
    }

    $list = array_keys($set);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}
