# AureusERP — Accounting Capability Gap Analysis

**Audience:** Stakeholders evaluating AureusERP for a snacks-manufacturing (FMCG, medium-to-large enterprise) deployment.
**Basis:** All findings verified directly against the codebase (`plugins/webkul/*`, database schema) on 2026-09-07. Nothing in this document is assumed; each claim references its evidence.
**Related docs:** `SETUP.md` (local deployment), `scripts/setup_customization.php` (roles/branding).

---

## 1. Executive summary

AureusERP is a **real double-entry ERP** built on Filament/Laravel (an Odoo-Community-style plugin architecture) — *not* an ecommerce tool. Its operational backbone (Sales → Manufacturing → Inventory → Invoicing) is genuine and extendable.

Its **accounting depth, however, sits below mature accounting packages** such as Sage 50/Peachtree and QuickBooks. Four pillars a statutory-grade accounting system needs are absent entirely: a bank-reconciliation UI, fixed assets, an inventory-costing/valuation engine, and a period-close control. A further four are thin or missing (budgets, payroll, landed costs, analytic accounting).

**Bottom line:** as an *operational* system — order-to-cash, procure-to-pay, manufacturing execution — it is a credible foundation. As the **system of record for statutory accounting and costing**, it currently cannot close its own books. Recommend pairing it with a costing engine (build) and either a period-close/bank-rec layer (build) or an external GL (integrate) before relying on it for financial reporting in a medium-to-large FMCG enterprise.

**Overall readiness for the stated use case:**
| Dimension | Readiness | Notes |
|---|---|---|
| Operational ERP (orders, MOs, stock, invoicing) | 🟢 Good | Genuine depth, Odoo-lineage patterns |
| Food-grade inventory (lots, expiry, traceability) | 🟡 Adequate skeleton | Expiry fields exist; no QC, no FEFO enforcement |
| Financial accounting (GL integrity, close, assets) | 🔴 Significant gaps | Four pillars missing (§4) |
| Statutory reporting | 🟡 Partial | Core statements exist; no close discipline behind them |
| Ecommerce | ⚪ Not applicable / not present | Thin CMS only — see §6 |

---

## 2. What is present and real ✅

Verified by code inspection (`plugins/webkul/accounts/`, `products/`, `inventories/`, `manufacturing/`, `sales/`, `purchases/`, `support/`).

### 2.1 Core financial accounting

| Capability | Evidence | Maturity |
|---|---|---|
| Double-entry GL | `Move` / `MoveLine` models, journal types, chart-of-accounts UI (`AccountResource`) | Real; posts from invoices/bills/payments |
| AR / AP document cycles | Invoice, Bill, Credit Note, Refund resources with full Filament CRUD | Complete document lifecycles |
| Payments | `PaymentResource` + batch `PaymentRegister`; customer and vendor payments | Good |
| Tax engine | `Tax`, `TaxPartition` (distribution/partition), `TaxGroup` | **Compound-tax capable — stronger than basic QuickBooks** |
| Terms & localization primitives | `PaymentTerm` + `PaymentDueTerm`, `FiscalPosition` (+ account/tax mapping), `CashRounding`, `Incoterm` | Present |
| Banking data model | `BankStatement`, `BankStatementLine` tables + `BankAccountResource` UI for accounts | Data model only — see gap G1 |
| Reconciliation primitives | `PartialReconcile`, `FullReconcile` tables/models | Primitives only — see gap G1 |
| Multi-currency | `Currency` + `CurrencyRate` models (Odoo-style) | Present; rate-entry UI is basic |

### 2.2 Financial reporting

Reports verified at `plugins/webkul/accounting/src/Filament/Clusters/Reporting/Pages/` (the `accounting` plugin hosts the reporting UIs; the `accounts` plugin hosts the models/resources):

- Balance Sheet, Profit & Loss, Trial Balance
- General Ledger, Partner Ledger
- Aged Receivable, Aged Payable
- Each with CSV/XLSX export classes (`Pages/Exports/`)

### 2.3 Operational backbone (relevant context for FMCG)

| Area | Evidence |
|---|---|
| Manufacturing | BOMs **with byproducts** (`BillOfMaterialByproduct` — relevant for snack/oil co-products), MOs, Work Orders, Work Centers with capacity + productivity-loss logging, Unbuild Orders |
| Food-grade inventory | Multi-warehouse, locations, putaway/routes; **Lots with `expiration_date`, `removal_date`, expiry reminders** (`inventories_lots` migration); Traceability settings; packaging units; full UoM set incl. kg/g/t/lb/oz |
| Procurement | RFQ → PO → receipt → vendor bill cycle |
| Sales | Quotation → SO → delivery → invoice cycle |
| Barcode | Shop-floor scanning plugin |
| HR (context only) | Employees, Recruitment, Time-off, Timesheets — **no payroll engine** |

### 2.4 Extension surface (verified by our own work)

We built and shipped two custom reports in the app namespace (`app/Filament/Pages/Reports/`) attached to the existing Accounting → Reporting cluster without touching `plugins/webkul/*`. This demonstrates the platform's claimed extensibility is real: plugin updates won't clobber app-level customizations, and Shield-based permissions integrate cleanly.

---

## 3. Method & scope of verification

- Full-text search across `plugins/webkul/*` for: `asset`, `budget`, `fiscal_year`, `period lock`, `closing entry`, `analytic`, `fifo`, `avco`, `cost_method`, `valuation`, `landed`, `cart`, `checkout`, `gateway`.
- Directory enumeration of every plugin's `src/Models`, `src/Filament/Resources`, `src/Filament/Clusters`.
- Database schema inspection (PostgreSQL 18, information_schema) for statement/reconciliation/lot tables and product cost fields.
- Live UI verification where relevant (Companies, Contacts, custom reports) in a real browser session.

Absence claims below are based on **zero matches** for the feature's models, migrations, Filament resources, and settings.

---

## 4. Core accounting gaps ❌

Severity: 🔴 blocking for statutory use · 🟠 major for the stated enterprise scale · 🟡 moderate/deferred.

### G1 — Bank reconciliation UI · 🔴
**Status:** `accounts_bank_statements` / `accounts_bank_statement_lines` tables exist; **no Filament resource, no pages, no import flow** anywhere in the codebase.
**Impact:** The single most daily-used accounting workflow in Sage/QB cannot be performed. Teams would reconcile in spreadsheets outside the system, breaking the audit trail.
**Effort:** Medium — the data model is ready; this is UI + matching workflow (we estimate days, not weeks, given the Reporting-cluster pattern is proven).

### G2 — Fixed assets · 🔴
**Status:** No asset module in any plugin (no models, migrations, or resources match `asset`).
**Impact:** No capitalization, depreciation schedules, or disposal accounting. Balance sheet fixed-asset figures cannot be produced by the system.
**Effort:** Medium-high — or integrate an external asset register.

### G3 — Inventory costing / perpetual valuation · 🔴
**Status:** `products_products.cost` is a single static decimal. No FIFO/AVCO/standard-cost engine, no valuation layers, no automated COGS postings from deliveries (searched `fifo`, `avco`, `cost_method`, `valuation` across products + inventories plugins).
**Impact:** **COGS will not post itself.** Gross margin in P&L is not trustworthy at transaction scale; stock value on the balance sheet is a manual figure. This is the deepest structural gap for a manufacturer.
**Effort:** High — this is engine work (cost layers, receipt/consumption valuation, variance accounts).

### G4 — Period close / fiscal-year control · 🔴
**Status:** No fiscal-year entity, no period locking, no year-end closing-entry generation (searched `fiscal_year`, `period lock`, `closing entry`).
**Impact:** Nothing prevents posting into closed periods; no soft-close/hard-close discipline; year-end is manual journal work.
**Effort:** Medium.

### G5 — Budgets · 🟠
**Status:** Absent entirely.
**Impact:** No budget-vs-actual anywhere; the P&L cannot be put in management context.

### G6 — Payroll · 🟠
**Status:** HR modules track employees and time, but there is no payroll computation engine or GL posting.
**Impact:** Payroll must run externally; wage journals entered manually.

### G7 — Landed cost · 🟠
**Status:** No module found.
**Impact:** Freight, duty, and handling on imported raw materials (typical for FMCG) cannot be capitalized into stock value — directly interacts with gap G3.

### G8 — Analytic accounting · 🟡
**Status:** Only a thin `analytic_records` model (single-table; no plans, no distribution).
**Impact:** Cost/profit center reporting (by production line, brand, channel) has no native home — relevant for FMCG brand/SKU profitability analysis.

### G9 — Secondary gaps worth noting · 🟡
- **No audit-trail module** (who changed what, when) beyond framework basics.
- **Currency rate UI is basic** (models exist, no rate-import workflow).
- **No approval workflows** (invoice/PO approval matrices expected at enterprise scale).

---

## 5. FMCG snacks-manufacturer fit assessment

### 5.1 Genuinely strong for the sector

| Requirement | Status |
|---|---|
| BOM with co-products (meal/oil byproducts) | ✅ Native (`BillOfMaterialByproduct`) |
| Work-center capacity & productivity loss | ✅ Native |
| Lot/ expiry tracking skeleton | ✅ `expiration_date`, `removal_date`, expiry reminders |
| Traceability settings | ✅ Present (`TraceabilitySettings`) |
| Weight-based UoMs (kg/g/t/lb/oz) + packaging | ✅ Full UoM engine |
| Multi-warehouse, routes, putaway | ✅ Native |
| Shop-floor scanning | ✅ Barcode plugin |

### 5.2 Missing at medium-to-large FMCG scale

| Requirement | Status | Consequence |
|---|---|---|
| **Quality control** (incoming/in-process inspections, holds, COA) | ❌ No module | Food-safety workflows unsupported |
| **Costing** (standard cost + variance or FIFO layers) | ❌ Gap G3 | Margins per SKU unknown |
| **Catch weight** (order cases, settle kg) | ❌ No evidence | Common FMCG pain point unaddressed |
| **FEFO enforcement** | 🟡 Fields exist, no enforced removal strategy | Manual discipline required |
| EDI / retailer connectors | ❌ None | Large retailers require this |
| Demand forecasting / MRP run | 🟡 Procurement reordering exists; no forecast engine | Planning is manual |
| Subcontracting UI | ❌ No evidence | Co-manufacturing unsupported natively |

---

## 6. "Is it more like ecommerce?" — No

Verified: the `website` plugin contains only `Page` and blog models — **no cart, no checkout, no storefront product catalog, no payment-gateway integration** (searched `cart`, `checkout`, `Cart` across `website` and `sales`). The `payments` plugin records **customer/vendor payments against invoices**; it is not a Stripe/PayPal-style storefront checkout.

AureusERP is **ERP-first** (Odoo-Community lineage: Sales/Purchase/Inventory/Manufacturing/Accounting/HR). If D2C ecommerce is needed, run a separate storefront and integrate through the exposed REST surface (`admin/api/v1/...` routes verified).

---

## 7. Recommendations

**R1 — Do not adopt as sole system of accounting record today.** The four 🔴 gaps mean the GL cannot be closed or trusted for statutory reporting without external spreadsheets.

**R2 — Priority build order (if extending in-house, leveraging the proven app-namespace pattern):**
1. **Bank reconciliation UI** (G1) — smallest effort, largest daily value; data model already exists.
2. **Period close discipline** (G4) — posting-date locks + fiscal years; relatively small.
3. **Costing/valuation engine** (G3) — the hardest but most transformative; consider FIFO layers on the existing move-line structure, with a standard-cost fallback as an interim.
4. **Fixed assets** (G2) — or integrate a dedicated asset register that posts summary journals.

**R3 — Hybrid alternative:** keep AureusERP as the operational system and post summarized journals to an external GL (e.g., via its REST API) until G1–G4 are closed. This is the fastest path to audit-ready books.

**R4 — For FMCG scale, budget for:** QC module (or 3rd-party LIMS integration), EDI connectors, and a decision on catch-weight handling. These are procurement/operations blockers independent of accounting.

**R5 — Re-evaluate trigger:** if Webkul ships an `accounting`-plugin update adding valuation/period-close features, re-run this analysis — the plugin architecture makes upstream additions plausible.

---

## Appendix A — Evidence index

| Claim | Evidence |
|---|---|
| GL/documents/tax present | `plugins/webkul/accounts/src/Models/` (45 models enumerated), `Filament/Resources/` (20 resources) |
| Reports present | `plugins/webkul/accounts/src/Filament/Clusters/Reporting/Pages/*` (7 statements) |
| Bank statement tables, no UI | `accounts/database/migrations/*bank_statement*`; zero Filament references to `BankStatement` |
| No assets/budgets/fiscal-year/landed | Directory + full-text search across `plugins/webkul/*` returned zero matches |
| Static cost only | `products_products.cost decimal(15,4)`; no `cost_method`/`valuation` matches |
| Lots + expiry | `inventories/database/migrations/*create_inventories_lots_table.php` (lines 20–23) |
| BOM byproducts | `manufacturing/src/Models/BillOfMaterialByproduct.php` |
| No ecommerce | `website/src/Models/` = Page + blog only; `cart`/`checkout` search: zero matches |
| Multi-currency | `support/src/Models/Currency.php`, `CurrencyRate.php` |

*Prepared from live codebase inspection, 2026-09-07. Reproduce any claim with the paths above.*
