# Accounting Module — FMCG Fit-Gap Report

## Snacks Manufacturer (Medium to Large Enterprise)

- **Date:** 2026-09-07
- **Codebase:** AureusERP (`/Users/sohaib/Projects/aureuserp`), Laravel 13 + Filament 5
- **Scope:** `plugins/webkul/accounting` (UX layer) + `plugins/webkul/accounts` (posting engine)
- **Method:** code-verified — model/cluster/enum/migration counts, field inspection, absence grep across both plugins. Absence claims mean "no matching code found", not "not in roadmap".

---

## 1. Executive summary

The module is a **solid Odoo-style SMB core**: real double-entry (draft → posted → cancel + reversals), six journal types, multi-level taxes with fiscal positions, payment matching with partial/blocked states, multi-company scoping, dated currency rates, and seven exportable financial reports. It will run a **single-plant, domestic, distributor-billing pilot** today.

It is **not yet fit for a medium-to-large multi-plant snacks operation** without additions. Ranked blockers:

1. **No cost/profit-center (analytic) accounting** — cannot report P&L by plant, brand, or depot.
2. **No trade-scheme/rebate engine** — schemes are 5–15% of FMCG net revenue; only manual credit notes exist.
3. **No tax-compliance automation** — tax is computed but GST returns, e-invoice/e-way bill, and TDS/TCS are absent.
4. **Bank-reconciliation engine without UI** — `BankStatement` / full / partial reconcile models exist in `accounts`, but the `accounting` plugin exposes no statement-matching screens.
5. **No fixed-asset register, no budgets, no period locks** — plant capex, annual planning, and clean month-end close stay in spreadsheets.
6. **No intercompany/STO or landed-cost accounting** — bites as soon as you run >1 plant/depot or import raw materials.

**Verdict:** approve for pilot, condition full rollout on closing gaps 1–4 at minimum.

---

## 2. Module architecture (as built)

| Layer | Plugin | Stats | Role |
| --- | --- | --- | --- |
| UX / menus / reports | `accounting` (`Webkul\Accounting`) | 24 models, 168 Filament files, **0 migrations**, 0 seeders | Clusters, resources, 7 reports, settings pages. Depends on `accounts`. |
| Posting engine | `accounts` (`Webkul\Account`) | 45 models, 169 Filament files, **71 migrations**, 26 seeders | Real tables: moves, move lines, journals, taxes, statements, reconciliations, payment register. |

Key point: `accounting` models are largely facades over `accounts` tables. Any new accounting feature (assets, budgets, analytic dimensions) must extend the **engine schema** in `accounts`, then surface UI in `accounting` clusters.

### 2.1 Navigation clusters (`accounting`)

- **Accounting:** Journal Entries, Journal Items (line-level explorer + exporter)
- **Customers:** Customers, Invoices (+ManagePayments), Credit Notes, Payments (+ManageInvoices), Products
- **Vendors:** Vendors, Bills (+ManagePayments), Refunds, Payments (+ManageBills), Products
- **Configuration:** Chart of Accounts, Journals, Taxes, Tax Groups, Fiscal Positions, Payment Terms, Currencies, Cash Rounding, Incoterms, Product Categories/Attributes
- **Reporting:** General Ledger, Trial Balance, Balance Sheet, Profit & Loss, Partner Ledger, Aged Receivable, Aged Payable (each with Excel export)
- **Settings:** Default Accounts, Taxes, Products, Customer Invoice defaults

### 2.2 Document types (`MoveType` enum)

`entry` (manual journal), `out_invoice` / `out_refund` (customer invoice / credit note), `in_invoice` / `in_refund` (vendor bill / refund), `out_receipt` / `in_receipt` (payments). Covers quote-to-cash and procure-to-pay paperwork.

### 2.3 Posting lifecycle (`MoveState`, `PaymentState`)

- Move: `draft → posted → cancel`, plus `MoveReversal` model.
- Payment tracking: `not_paid / in_payment / paid / partial / reversed / blocked` — `partial` and `blocked` matter for distributor collections.

### 2.4 Tax engine (strongest sub-system)

`Tax` (amount types, scope, use, exigibility, formula, price-include overrides) + `TaxGroup` + `TaxPartition` lines (account, repartition type, document type, factor %) + `FiscalPosition` with account- and tax-mapping tables + `TaxTaxes` (tax-on-tax). This is Odoo-grade rate logic and handles multi-rate GST-style structures **computationally**.

### 2.5 Journals, payments, partners

- Journal types: sale, purchase, cash, bank, credit, general (`JournalType`).
- Payment side: `Payment`, `PaymentRegister`, `PaymentMethod/MethodLine`, `PaymentDueTerm`, `PaymentTerm`, `AccountPaymentRegisterMoveLine`.
- Partner finance: `credit_limit` / `debit_limit` on Partner, `PartnerCompanyProperty` (receivable/payable defaults), early-pay discount + installment enums present.

### 2.6 Multi-entity / multi-currency base

`BelongsToCompany` scoping + `Company` model; `Currency` + dated `CurrencyRate` (decimal:6). No currency-revaluation run found.

---

## 3. FMCG requirement profile (assumptions)

High-volume low-margin distributor billing (hundreds–thousands of invoices/day at scale), multi-plant production + depot/C&F network, trade schemes and promo claims, credit exposure across distributors, imported inputs (oils, packaging films), batch/expiry discipline (food safety), GST-heavy domestic compliance (India-context vendor), seasonal promo budgeting, plant-level P&L accountability, capex-heavy plants, bank collections at scale (UPI/NEFT), audit scrutiny.

---

## 4. Fit-gap matrix

| # | Requirement | Rating | Notes |
| --- | --- | --- | --- |
| F1 | Customer invoicing, credit notes, refunds | ✅ Fit | Full doc set + payment linking |
| F2 | Vendor bills, refunds, payments | ✅ Fit | Same, purchase side |
| F3 | Manual journals + line explorer | ✅ Fit | Entry resource + Journal Items table/export |
| F4 | Multi-rate tax computation + fiscal positions | ✅ Fit | Best-in-module sub-system |
| F5 | Standard reports (GL/TB/BS/P&L/Partner/Aged) + Excel | ✅ Fit | 7 reports, each with exporter |
| F6 | Multi-company scoping, multi-currency recording | ⚠️ Partial | Scoping + dated rates exist; no revaluation run |
| F7 | Distributor credit limits | ⚠️ Partial | Fields exist; no hold/block workflow, no dunning (0 files) |
| F8 | Bank reconciliation at scale | ⚠️ Partial | Engine (`BankStatement`, `Full/PartialReconcile`) exists; **no UI in `accounting`** |
| F9 | Inventory valuation ↔ GL / COGS methods | ⚠️ Partial | `CategoryCompanyAccount` exists; no valuation-method wiring found |
| F10 | Batch/expiry provisions + write-offs | ⚠️ Partial | Lots live in inventory; no provision/write-off flow in accounting |
| F11 | Cost/profit centers by plant/brand/depot | ❌ Gap | No analytic accounting (0 files) |
| F12 | Trade schemes, rebates, promo-claim settlement | ❌ Gap | Manual credit notes only (0 files) |
| F13 | GST returns / e-invoice / e-way bill / TDS-TCS | ❌ Gap | "gst" code hits are false positives; withholding 0 files |
| F14 | Fixed assets + depreciation runs | ❌ Gap | Only COA type enums (`asset_fixed`, `expense_depreciation`); no register/engine |
| F15 | Budgets by plant/brand/SKU | ❌ Gap | 0 files |
| F16 | Cash-flow statement + forecast | ❌ Gap | 0 files |
| F17 | Period locks / year-end close | ❌ Gap | No lock-date/close wizard found |
| F18 | Intercompany + stock-transfer accounting | ❌ Gap | 0 files |
| F19 | Landed cost (freight/duty into inventory cost) | ❌ Gap | 0 files |
| F20 | Immutable accounting audit trail | ⚠️ Partial | Chatter activity logs only; no accounting-grade audit log |

---

## 5. Detailed gap notes

### G11 — Analytic (cost-center) accounting [CRITICAL]

No dimensions on `MoveLine`; P&L cannot slice by plant, brand, depot, or SKU category. For a multi-plant snacks maker this blocks accountability reporting entirely. COA-segment workarounds scale poorly. **Build:** analytic-dimension tables + mandatory/optional tagging on move lines + report filters. Touches engine schema — largest item.

### G12 — Trade promotion management [CRITICAL]

FMCG schemes (slab discounts, QPS, display allowances, damage claims) need accrual → claim → settlement with deduction matching against distributor payments. Today: only manual credit notes and `BLOCKED` payment state as building blocks. **Build:** scheme/accrual/claim models reusing CreditNote + payment matching; deduction-coding on receipts.

### G13 — Tax compliance automation [HIGH]

Computation ≠ compliance. Missing: GSTR-1/3B datasets, e-invoice (IRN/QR) integration, e-way bill linkage, TDS/TCS sections and challan mapping. **Build:** read-only compliance extracts first (uses existing tax partitions), then API integrations.

### G8 — Bank-rec operability [HIGH]

Reconciliation math exists; accountants cannot use it — no statement import/matching screens in `accounting` clusters (grep: 0 Filament hits). At FMCG collection volumes this forces offline matching. **Build:** surface `BankStatement` + matching UI (smallest high-value item).

### G7 — Credit & collections [HIGH]

Limits are stored, not enforced; no dunning letters, follow-up plans, or credit-hold on order release. Pair with Aged Receivable (exists) to close the loop.

### G14/G15/G17 — Assets, budgets, close control [MEDIUM]

Capex plants need an asset register + depreciation runs posting to `expense_depreciation`; brand/plant budgeting needs a budget model + variance reports; auditors need lock dates and a documented close checklist. All absent.

### G18/G19 — Intercompany & landed cost [MEDIUM, grows with footprint]

Single-plant domestic pilot can defer; multi-depot network or imported inputs make these urgent (transfer pricing postings, freight/duty capitalization).

### G6/G9/G10/G20 — Currency revaluation, valuation linkage, expiry provisions, audit log [MEDIUM-LOW]

Needed for correctness at scale but each is bounded work on top of existing models.

---

## 6. Risk assessment

| Risk | Likelihood | Impact | Driver |
| --- | --- | --- | --- |
| Plant/brand profitability invisible | Certain (multi-plant) | High | G11 |
| Scheme leakage / audit qualification | Likely | High | G12 |
| Compliance penalties / filing effort | Likely | High | G13 |
| Month-end close stretches past T+5 | Likely | Medium | G8, G17 |
| Collection reconciliation errors | Likely at volume | Medium | G8, G7 |
| Capex/depreciation misstatement | Possible | Medium | G14 |

---

## 7. Recommended roadmap

- **Phase 0 — Pilot (now):** single plant, domestic distributors; enforce credit limits procedurally; close in spreadsheets. Accept G11–G19 as manual workarounds with a sunset date.
- **Phase 1 — Operate (highest ROI):** bank-rec UI on existing engine; credit-hold + dunning; compliance extracts (GSTR datasets); lock dates + close checklist.
- **Phase 2 — Control:** analytic dimensions on move lines + plant/brand P&L; asset register + depreciation; budgets + variance.
- **Phase 3 — Scale:** scheme/rebate engine with claim settlement; intercompany/STO; landed cost; currency revaluation; expiry-provision automation.

Suggested build order by value/effort: bank-rec UI → lock dates → compliance extracts → analytic dimensions → dunning/credit-hold → assets → budgets → schemes → intercompany/landed cost.

---

## 8. Appendix — evidence index

- Clusters: `plugins/webkul/accounting/src/Filament/Clusters/{Accounting,Customers,Vendors,Configuration,Reporting,Settings}`
- Engine models: `plugins/webkul/accounts/src/Models/` (45 files; `Move`, `MoveLine`, `MoveReversal`, `BankStatement(Line)`, `Full/PartialReconcile`, `Reconcile`, `PaymentRegister`, `FiscalPosition(Account|Tax)`, `TaxPartition`, `ProductCompanyAccount`, `CategoryCompanyAccount`, …)
- Enums: `plugins/webkul/accounts/src/Enums/` (29 files: `MoveState`, `MoveType`, `PaymentState`, `JournalType`, `AccountType`, `AmountType`, `RepartitionType`, `DocumentType`, `EarlyPayDiscount`, `InstallmentMode`, …)
- Reports + exporters: `…/Accounting/Filament/Clusters/Reporting/Pages/{GeneralLedger,TrialBalance,BalanceSheet,ProfitLoss,PartnerLedger,AgedReceivable,AgedPayable}.php` (+ `Exports/`)
- Absence greps (0 files unless noted): budgets; analytic/cost-center; withholding/TDS; cash-flow; lock-date/period-close; intercompany; deferred revenue; landed cost; dunning; accounting audit trail. `FixedAsset`/`Depreciation` hits are COA-type enums and report labels only; `gst` hits are substring false positives.
- Away-from-text note: 779/1844 Shield permission names contain `::` (e.g. `view_any_accounting_journal::entry`), a slug bug that does not block granting but should be reported upstream.
