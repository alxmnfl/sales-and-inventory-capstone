# Lucky 8 Hydraulics — Project Overview

Lucky 8 is a multi-branch retail management system for a hydraulics hose/fittings
company (18–19 branches). It has three parts that share one MySQL database
(`lucky8_db`):

1. **Landing Page** — login/registration gateway
2. **POS** — branch-level point-of-sale terminal
3. **Admin Console** — head-office dashboard, inventory, reporting, and an
   optional Python forecasting service

All PHP pages use plain `mysqli` (no framework/ORM) and PHP sessions for auth.
There is no build step — files are served directly by XAMPP/Apache.

---

## Landing Page/ — Authentication gateway

| File | Purpose |
|---|---|
| `login.php` | The single entry page. Renders a two-tab (Sign In / Register) UI. Reads flash messages (`login_error`, `reg_error`, `reg_success`) from the session set by the two processors below. |
| `login_process.php` | Handles the sign-in POST. Verifies email/password (`password_verify`), checks account `status` (rejects `rejected` accounts), then starts the session and redirects: `administrator` → Admin Console, `branch_staff` → POS (also seeds `pos_cashier`/`pos_cashier_branch` session keys). |
| `register.php` | Handles the registration POST. Validates required fields, email format, password length/match, and duplicate email/employee ID, then inserts a new `users` row (auto-approved — no pending-approval flow currently active). |
| `db.php` | Shared DB connection. Defines `DB_HOST/USER/PASS/NAME` constants (`root`/no password/`lucky8_db`) and opens `$conn` as a `mysqli` instance. Included by nearly every other PHP file via `require_once`. |
| `login.css` | Styling for the split-panel login/register screen. |
| `login.js` | Client-side behavior for `login.php`: the hardcoded 18-branch picker (with search/filter) used by the registration form, tab switching, password show/hide, and role-card selection. |

---

## POS/ — Branch point-of-sale terminal

Session-gated by `$_SESSION['pos_cashier']` (set at login for `branch_staff`,
or derived from an admin's own session if they land here directly).

| File | Purpose |
|---|---|
| `index.php` | The POS screen itself: product grid with category tabs/search, a running cart, a checkout modal (Cash / Credit Card / Corporate Account with change calculation), a sale-complete receipt overlay, and an "Add Product" modal for branch staff to manage their own branch's catalog. |
| `pos.js` | All front-end logic: loading/filtering/rendering products, cart math (12% VAT), checkout flow, calling the API endpoints below via `fetch`, and rendering the receipt. |
| `style/base.css`, `header.css`, `products.css`, `cart.css`, `modal.css`, `sale-complete.css` | Styling for the POS layout, split by section: base/variables, header, product grid/cards, cart sidebar, modals (checkout + adjust-stock), and the sale-complete receipt screen. |
| `logout.php` | Destroys the session and redirects to the login page. |
| `api/products.php` | Returns (JSON) all products for the logged-in cashier's **own branch only** — POS is branch-scoped. |
| `api/save_product.php` | Add or edit a product. Enforces that edits can only target a product belonging to the cashier's branch. Writes an `ADD_PRODUCT`/`EDIT_PRODUCT` row to `audit_trail`. |
| `api/delete_product.php` | Deletes a product (scoped to the cashier's branch) and logs a `DELETE_PRODUCT` audit entry. |
| `api/complete_sale.php` | Finalizes a sale inside a DB transaction: generates a `SAL-XXXXXXXX-XXX` transaction ID, inserts into `pos_sales` + `pos_sale_items`, decrements `pos_products.stock` (locking rows with `FOR UPDATE` to prevent overselling), rolls back on any error (e.g. insufficient stock), and logs a `COMPLETE_SALE` audit entry. |
| `deliveries.php` / `api/deliveries.php` / `src/deliveries.js` | Branch-staff view of inbound **deliveries** from head office: check each line against what arrived, then confirm (adds stock) or dispute. |
| `transfers.php` | Branch-staff hub for **inter-branch transfers**. Four parts: (1) *Where's the stock?* — search a product and see which other branches hold spare stock (On-Hand + Surplus = stock beyond a 10-unit buffer), grouped *Nearby (same region)* vs *Other branches*; (2) *New request* — pick a source branch, add products + quantities from this branch's catalog, send; (3) *Incoming requests* (this branch is the source) — Approve & Ship (locks & decrements this branch's stock in a transaction) or Decline with a reason; (4) *My requests* — Confirm Receipt (adds shipped qty to stock, creating the product locally if new) or Cancel while still pending. |
| `api/transfers.php` | JSON endpoint for the transfer flow. Actions: `stock_lookup` (surplus per branch for a SKU, split nearby/other by `branch_directory.region`), `request`, `approve` (ship — `FOR UPDATE` on source `pos_products`, caps qty at on-hand), `reject`, `cancel`, `receive`. Writes `REQUEST_/SHIP_/REJECT_/CANCEL_/RECEIVE_TRANSFER` audit rows. |
| `src/transfers.js` | Front-end for `transfers.php`: cross-branch lookup, the paginated request picker, and the approve / decline / receive / cancel modals. |
| `sql/migration_branch_transfer.sql` | Canonical DDL for `branch_transfers`, `branch_transfer_items`, `branch_directory` (also auto-created by `Landing Page/php/transfer_schema.php`). |
| `setup.sql` | Initial schema: `pos_products`, `pos_sales`, `pos_sale_items`, `audit_trail`. Run once to bootstrap a fresh database. |
| `migration.sql` | Incremental schema change: adds `branch`/`added_by` columns to `pos_products`, changes the SKU unique key to be per-branch (`sku_branch`), and creates `audit_trail` for databases that predate it. |

---

## Admin Console/ — Head-office dashboard

Every page here is gated to `$_SESSION['user_role'] === 'administrator'`
(redirects to login otherwise), and every page `include`s `sidebar.php`.

| File | Purpose |
|---|---|
| `sidebar.php` | Shared layout partial included by every admin page: builds the left nav (with live badges for staff count / at-risk-of-stockout count), the notification bell (last 5 audit entries) and the user profile dropdown. Not meant to be requested directly. |
| `index.php` | Main dashboard. KPI cards (MTD revenue vs. last month, units sold, low-stock alerts, active branches), a sales trend chart, an ABC inventory classification donut (top 20% / next 30% / remainder by revenue), and three "Branch Intelligence" panels (fast movers, critical stock, predictive alerts, audit trail) that are lazy-loaded client-side from `api/*.php`. |
| `inventory.php` | Full product CRUD across all branches: KPI cards (total products, stock, out-of-stock, inventory value), a filterable/searchable table, and Add/Edit modals. Also handles the `add`/`edit`/`delete` POST actions directly (no separate API file for this page). |
| `sales.php` | Sales history browser: today/week/month revenue KPIs, a 30-day sales chart, and a filterable (branch + date range) transactions table. |
| `movement.php` | "Movement Intel" — week-over-week unit-velocity comparison per product (gaining/declining/stable), plus a recent audit-activity feed. |
| `branches.php` | One card per branch showing product count, stock, low-stock count, staff count, and MTD revenue (with % change vs. last month). |
| `users.php` | User account management (list, add, edit role/status/branch, delete). Handles its own CRUD POST actions. |
| `forecasts.php` | Demand-forecast UI. Calls the separate Python Flask service (see below) for a linear-regression revenue/units forecast and a stock-out risk table; shows a "server not running" notice with setup instructions if the Python API is unreachable. |
| `forecast_api.py` | **Standalone Python Flask microservice** (not run by Apache/PHP — started manually with `python forecast_api.py`, listens on `127.0.0.1:5001`). Connects to the same `lucky8_db` MySQL database directly. Endpoints: `/api/status` (health check), `/api/forecast` (fits a per-day `LinearRegression` on the last 90 days of sales to project revenue/units N days ahead), `/api/product_forecast` (ranks products by days-until-stockout using 30-day average velocity). `forecasts.php` degrades gracefully if this process isn't running. |
| `audit-trail.php` | Full, paginated, filterable (branch/action/user/date range) view of the `audit_trail` table, with KPIs (today's activity, total deletions). |
| `reports.php` | Report builder: pick Sales / Inventory / Audit, preview up to 50 rows, and export the full result as CSV (`export=sales|inventory|audit` query param streams a `Content-Disposition: attachment` CSV). |
| `deliveries.php` / `src/deliveries.js` | Head-office side of the delivery flow: build a delivery document for a branch and track its status. |
| `transfers.php` / `src/transfers.js` | **Read-only** monitor of every inter-branch transfer (KPIs, filter by branch/status, per-transfer line-item view) plus the **Branch Region Directory** editor — the one write on this page. Setting a region on two branches makes them "Nearby" in the POS stock lookup. Staff, not admins, approve transfers. |
| `admin.css` | Shared styling for the whole Admin Console (KPI cards, charts, sidebar, tables, etc.) — used by every page above. |
| `admin.js` | Shared client-side logic for `index.php`: builds the Chart.js sales-trend and ABC donut charts, and fetches/renders the three lazy-loaded intelligence panels (fast-moving, critical stock, predictive alerts) plus paginated audit trail loading. |

### Admin Console/api/ — JSON endpoints consumed by `index.php`

All require an authenticated administrator session (401 JSON otherwise).

| File | Purpose |
|---|---|
| `branches.php` | List of distinct branch names (for the dashboard's branch filter dropdown). |
| `fast_moving.php` | Top N products by units sold in the last 30 days (optionally filtered by branch). Powers the "Fast-Moving Items" panel. |
| `critical_stock.php` | Products with stock below a threshold (default 10), optionally by branch. Powers the "Critical Stock Levels" panel. |
| `predictive_alerts.php` | Products projected to run out of stock within 14 days, based on 30-day average daily sales velocity. Powers the "Predictive Stock Alerts" panel — this is the PHP/SQL-only forecast, distinct from the Python service used by `forecasts.php`. |
| `audit_trail.php` | Paginated audit log fetch (used for the dashboard's "load more" audit feed). Degrades gracefully (empty list + notice) if the `audit_trail` table doesn't exist yet. |

---

## Images/

| File | Purpose |
|---|---|
| `background.jpg` | Background image asset (used by the login page's left panel). |

---

## Data flow summary

```
Landing Page/login.php
        │  (login_process.php authenticates against `users` table)
        ▼
   role = administrator?  ──yes──▶  Admin Console/index.php  ──▶  api/*.php (dashboard panels)
        │no                                  │
        ▼                                    ├──▶ forecasts.php ──▶ forecast_api.py (Flask, port 5001)
   POS/index.php                             └──▶ inventory.php / sales.php / users.php / branches.php /
        │  ├── deliveries.php                       movement.php / audit-trail.php / reports.php /
        │  └── transfers.php  ◀───────────────▶     deliveries.php / transfers.php (monitor + regions)
        ▼
   POS/api/*.php  ──▶  pos_products / pos_sales / pos_sale_items / audit_trail /
                       inventory_deliver* / branch_transfer* / branch_directory (MySQL: lucky8_db)
```

Inter-branch transfers move stock **POS ↔ POS**: the requesting branch raises a
request, the source branch's staff approve & ship (their `pos_products.stock`
drops), and the requesting branch confirms receipt (their stock rises). The
Admin Console only watches and maintains `branch_directory` regions.

Every write action (product add/edit/delete, completed sale, delivery receipt,
transfer ship/receive) writes a row to `audit_trail`, which both the POS-adjacent
movement view and the Admin Console's dashboard/audit-trail pages read back.
