-- ─────────────────────────────────────────────────────────────────────────────
-- Normalize `branch` strings so every table uses ONE canonical spelling.
--
-- Canonical form = the spelling used by pos_products (the seed catalog):
--   UPPERCASE, real "—" (U+2014), real "Ñ" (U+00D1).
--
-- Before this migration the same branch was stored three different ways:
--   pos_products : LUCKY 8 — LAS PIÑAS CITY   (uppercase Ñ)
--   audit_trail  : LUCKY 8 — LAS PIñAS CITY   (lowercase ñ)  + 1 row mojibake'd to "?"
--   pos_sales    : LUCKY 8 — LAS PIñAS CITY   (lowercase ñ)
--   users        : Lucky 8 — Las Piñas City   (title case, lowercase ñ)
--
-- which made `SELECT DISTINCT UPPER(branch)` produce duplicate dropdown entries
-- and broke `UPPER(branch) = '...'` branch filtering for the Ñ branches.
--
-- Safe to re-run (idempotent).
-- Backup: Admin Console/sql/_branch_backup_20260829.sql
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- 1. Repair the row whose "—" and "ñ" were destroyed (stored as literal "?").
UPDATE audit_trail
SET    branch = 'LUCKY 8 — LAS PIÑAS CITY'
WHERE  branch = 'LUCKY 8 ? LAS PI?AS CITY';

-- 2. Fold the lowercase-ñ spellings onto the canonical uppercase-Ñ spelling.
UPDATE audit_trail SET branch = 'LUCKY 8 — LAS PIÑAS CITY' WHERE branch = 'LUCKY 8 — LAS PIñAS CITY';
UPDATE audit_trail SET branch = 'WIN FLEX — BAÑAG'         WHERE branch = 'WIN FLEX — BAñAG';

UPDATE pos_sales   SET branch = 'LUCKY 8 — LAS PIÑAS CITY' WHERE branch = 'LUCKY 8 — LAS PIñAS CITY';
UPDATE pos_sales   SET branch = 'WIN FLEX — BAÑAG'         WHERE branch = 'WIN FLEX — BAñAG';

-- 3. users holds title-case values; uppercase them to match. Do the Ñ branches
--    explicitly (MySQL UPPER() does not reliably fold ñ->Ñ across connections),
--    then uppercase the rest.
UPDATE users SET branch = 'LUCKY 8 — LAS PIÑAS CITY' WHERE branch = 'Lucky 8 — Las Piñas City';
UPDATE users SET branch = 'WIN FLEX — BAÑAG'         WHERE branch = 'Win Flex — Bañag';
UPDATE users SET branch = 'LIMA — DASMARIÑAS'        WHERE branch = 'Lima — Dasmariñas';
-- Everything else: uppercase unconditionally. (A `branch <> UPPER(branch)` guard
-- would be skipped by the case-insensitive collation, so just always fold.)
UPDATE users
SET    branch = UPPER(branch)
WHERE  branch NOT LIKE '%Ñ%' COLLATE utf8mb4_bin
  AND  branch NOT LIKE '%ñ%' COLLATE utf8mb4_bin;
