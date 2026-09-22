# AureusERP Logistics Extension Repository Audit

Document role: dated repository evidence at the baseline below. Recheck every source path and behavior before implementation. This audit cannot override confirmed business requirements, approved decisions, or the consolidated plan under [`plan/`](plan/README.md).

The source observations and evidence labels remain useful. The readiness recommendation, proposed target model, rollout sequence and open-decision table in this audit have been superseded by [`plan/02-DOMAIN-MODEL.md`](plan/02-DOMAIN-MODEL.md), [`plan/04-TARGET-ARCHITECTURE.md`](plan/04-TARGET-ARCHITECTURE.md), [`plan/08-IMPLEMENTATION-ROADMAP.md`](plan/08-IMPLEMENTATION-ROADMAP.md) and [`plan/12-OPEN-DECISIONS.md`](plan/12-OPEN-DECISIONS.md).

## Status and objective

This is an implementation-readiness audit, not an approved schema, design, or authorization to implement. It assesses whether the current AureusERP fork can safely host an outbound dispatch and transportation extension without duplicating stock, product, partner, accounts-payable, payment, or reconciliation ledgers.

Audit baseline: commit `6d1f0db07ca3bd57455c11d06bb64151f59cc83f`. All source references and line ranges below are against that commit. No customer, driver, employee, or financial records were read.

Evidence labels used below:

- **Inspected**: verified in source, manifests, lockfiles, generated installed-package metadata, or Git metadata.
- **Tested**: a command was executed in this audit and its result is stated.
- **Inferred**: a conclusion from inspected code that has not been executed here.
- **Unknown**: repository evidence is insufficient; business or runtime confirmation is required.

## Readiness conclusion

**Recommendation: approve a design-and-contract phase, not module implementation yet.** The repository has usable sales, inventory, partner, accounting, payment, company-scope, Filament, and plugin primitives. The critical blockers are:

1. The default plugin uninstall path is destructive and there is no effective disable lifecycle.
2. Existing product packaging and UoM records are mutable references; the sales line does not preserve the conversion factor used historically.
3. A logistics integration must call the inventory and accounting workflows, not update statuses or quantities directly; the exact idempotent orchestration contract is not yet an exposed core API.
4. Transport progress, phone-report provenance, distributor receipt verification, vehicle/provider role snapshots, load allocation, discrepancies, freight approval, and settlement linkage are not existing domains.
5. The non-Webkul vendor path fits Composer's wildcard, but the test bootstrap contains `plugins/webkul/...` assumptions that must be generalized.
6. Branches are represented as child Company records, while transactions carry `company_id`; Logistics must use the exact operating company/branch ID and must not invent a second branch-tenancy key.
7. Initial transport modes, packaging policy, approval roles, receipt evidence, settlement holds, and unfulfilled-quantity behavior remain business decisions.

The decisive factor is not UI complexity. It is preserving a single authoritative stock and accounting posting path under retries, concurrent planning, partial execution, and corrections.

## 1. Exact baseline

### Git

| Item | Recorded value | Evidence |
|---|---|---|
| Branch | `master` | **Inspected**, `git branch --show-current` |
| HEAD | `6d1f0db07ca3bd57455c11d06bb64151f59cc83f` | **Inspected**, `git rev-parse HEAD` |
| Commit date | `2026-09-12T04:22:55+05:00` | **Inspected**, `git show -s` |
| Upstream | `origin/master` at `9ece7f023e1c5972c21fa6095a2eb54e748a9763` | **Inspected** |
| Relationship | ahead 8, behind 0; merge-base is `origin/master` | **Inspected**, `git rev-list` / `git merge-base` |
| Initial working tree | clean | **Inspected** before creating these documents |
| Remote | `https://github.com/aureuserp/aureuserp.git` | **Inspected** |

The eight local commits include enterprise bank-reconciliation work and sales validation changes. This audit therefore uses the modified fork, rather than upstream `origin/master`, as its implementation baseline. That choice does not make the fork authoritative for business or domain behavior.

End-of-audit working tree: these two audit documents are untracked. `AGENTS.md` also changed concurrently after the initial clean baseline; the audit did not edit or revert it, so it remains an unrelated working-tree change that the repository owner must review.

### Backend runtime and dependencies

| Component | Manifest constraint | Locked | Actually installed/local |
|---|---:|---:|---:|
| PHP | `^8.3` | platform, not locked | `8.5.0` |
| Laravel | `^13.0` | `13.21.1` | `13.21.1` |
| Filament | `^5.0` | `5.7.6` | `5.7.6` |
| Livewire | `^4.0` | `4.3.3` | `4.3.3` |
| Sanctum | `^4.0` | `4.3.3` | `4.3.3` |
| Pest | `^4.4` | `4.7.5` | `4.7.5` |
| PHPUnit | transitive | `12.5.30` | `12.5.30` |
| Laravel Pint | `^1.27` | `1.29.3` | `1.29.3` |
| Laravel Boost | `^2.1` | `2.4.13` | `2.4.13` |
| Composer merge plugin | `^2.1` | `2.1.0` | `2.1.0` |

Manifest source: `composer.json:11-49`; installed/locked values were read from `composer.lock` and `vendor/composer/installed.json`. Composer is `2.8.12`.

**Risk:** `AGENTS.md` describes Laravel 11, Filament 4, and Livewire 3, but the actual manifest, lock, and installed tree are Laravel 13, Filament 5, and Livewire 4. Any implementation must follow current source and version-specific Laravel/Filament 5 behavior. Dependency upgrades are neither required nor authorized by this audit.

### Frontend runtime and dependencies

| Component | Manifest constraint | Locked | Installed |
|---|---:|---:|---:|
| Node | not constrained | n/a | `24.14.0` |
| npm | not constrained | lockfile v3 | `11.12.1` |
| Tailwind CSS | `^4.3.0` | `4.3.0` | `4.3.0` |
| Vite | `^5.0` | `5.4.19` | `5.4.19` |
| Laravel Vite plugin | `^1.0` | `1.3.0` | `1.3.0` |
| Axios | `^1.7.4` | `1.11.0` | `1.11.0` |

Manifest source: `package.json:4-18`; locked/installed values: `package-lock.json` and the corresponding `node_modules/*/package.json` files.

### Database and test configuration

- The local configured driver is PostgreSQL; credentials and database contents were not read. Laravel defines SQLite, MySQL, MariaDB, PostgreSQL, and SQL Server connections (`config/database.php:19-113`). **Inspected**.
- PHPUnit forces PostgreSQL and database name `aureuserp_testing` (`phpunit.xml:47-59`). **Inspected**.
- CI provisions disposable MySQL 8 and PostgreSQL 16 services (`.github/workflows/pest_tests.yml:12-50`) and then installs the ERP and runs Pest (`.github/workflows/pest_tests.yml:64-92`). **Inspected**, not run locally.
- The local test harness always bootstraps the ERP from `Tests\TestCase::setUp()` (`tests/TestCase.php:9-17`). Without prepared reuse it invokes `migrate:fresh --force` and `erp:install` (`plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php:214-249`). A suffix guard rejects a database not ending in `_testing` (`TestBootstrapHelper.php:373-383`), but a name guard alone does not prove disposability. **Inspected**.
- Reuse is explicit through `AUREUSERP_TEST_REUSE_DATABASE` (`config/testing.php:3-7`) and required-table checks (`TestBootstrapHelper.php:264-291`). `DatabaseTransactions` applies to feature tests (`tests/Pest.php:19-30`) but does not undo bootstrap/install work or persistent reusable fixtures. **Inspected/inferred**.

No test baseline was run because the configured local database was not independently proven disposable. The repository contains 204 PHP test files, including 17 sales, 40 inventory, 43 accounts, 15 purchases, and 21 custom reconciliation tests; file counts are inventory, not pass results.

## 2. Extension lifecycle

### Discovery and Composer loading

`plugins/shafique/logistics` is structurally compatible with the root Composer wildcard because `composer.json:84-92` merges `plugins/*/*/composer.json`. It is not currently registered: the generated Composer autoloader contains Webkul namespaces and no `Shafique\Logistics` namespace.

The minimum wiring required for a later authorized implementation would be:

- plugin-local Composer PSR-4 mapping and `extra.laravel.providers`, following `plugins/webkul/sales/composer.json:10-28`;
- a `LogisticsServiceProvider` extending `PackageServiceProvider` and a paired `LogisticsPlugin`;
- `Panel::configureUsing()` registration, following `plugins/webkul/sales/src/SaleServiceProvider.php:130-140`;
- install-state guarded Filament discovery, following `plugins/webkul/sales/src/SalePlugin.php:21-46`;
- plugin-local ACL configuration, policies, migrations, translations, tests, and only those routes/assets actually needed.

Plugin Manager does not scan arbitrary directories. It enumerates already registered Filament plugins, derives a service-provider class, and accepts providers extending `PackageServiceProvider` (`plugins/webkul/plugin-manager/src/Models/Plugin.php:58-97`). Plugin sync only catalogs loaded packages (`plugins/webkul/plugin-manager/src/Filament/Resources/PluginResource/Pages/ListPlugins.php:70-100`). **Inspected**.

### Reference plugins

Use `sales` as the primary first-party reference for metadata, settings, dependencies, install hooks, Filament registration, routes, ACL, and test layout (`plugins/webkul/sales/src/SaleServiceProvider.php:39-99`, `:130-140`; `plugins/webkul/sales/src/SalePlugin.php:21-46`; `plugins/webkul/sales/config/filament-shield.php:23-58`). Use `barcode` only where asset registration is actually needed (`plugins/webkul/barcode/src/BarcodeServiceProvider.php:41-52`).

Do not copy every reference feature. Logistics needs no public/customer portal, driver app, live GPS, bidding, or custom frontend asset bundle in the first slice.

### Registration, settings, permissions, routes, assets, translations

- `PackageServiceProvider::register()` constructs package metadata and merges plugin and Shield config (`plugins/webkul/plugin-manager/src/PackageServiceProvider.php:18-73`). **Inspected**.
- Migrations/settings and routes are loaded only for core or installed plugins (`PackageServiceProvider.php:115-160`, `:202-205`), while views/translations and `packageBooted()` are not centrally installation-gated (`:171-184`, `:216-218`). **Inspected**.
- Provider-side listeners, observers, schedules, and assets must therefore be explicitly guarded. Sales and Barcode demonstrate unconditional provider work (`plugins/webkul/sales/src/SaleServiceProvider.php:101-128`; `plugins/webkul/barcode/src/BarcodeServiceProvider.php:41-52`). **Inspected/risk**.
- Install executes dependencies, migrations/settings, seeders, plugin metadata, dependency links, permissions, and cache refresh (`plugins/webkul/plugin-manager/src/Console/Commands/InstallCommand.php:55-172`). Migration identity is basename-based (`:244-298`), so logistics migration names must be globally unique. **Inspected**.
- The Filament installer starts a web-process transaction but shells out to Artisan (`plugins/webkul/plugin-manager/src/Filament/Resources/PluginResource/Tables/PluginsTable.php:108-155`); that parent transaction cannot roll back migrations committed by the child process. **Inferred risk**.
- No dedicated upgrade command or stored installed-version migration lifecycle was found. `latest_version` is catalog metadata, not an upgrade protocol. **Inspected/unknown runtime policy**.

### Non-Webkul test-bootstrap gap

The proposed vendor path exposes three hard-coded test assumptions:

- API routes use `plugins/webkul/{plugin}/routes/api.php` (`TestBootstrapHelper.php:205-211`).
- plugin migration filtering recognizes only `plugins/webkul/...` (`TestBootstrapHelper.php:329-361`).
- installed-plugin probes are a fixed list (`TestBootstrapHelper.php:21-29`).

The smallest upstream-facing patch is to derive route and migration paths from loaded package/provider base paths and replace the fixed probe list with package metadata. That patch needs regression tests proving a fixture plugin under a non-Webkul vendor is discovered, installed in an isolated database, and has its API routes and migrations loaded. Do not place company code under `plugins/webkul` merely to bypass the test harness.

### Disable and uninstall

**Disable is missing.** The plugins table has `is_active` (`plugins/webkul/plugin-manager/database/migrations/2024_11_05_105102_create_plugins_table.php:14-25`), but no enable/disable action was found and `Package::isPluginInstalled()` ignores `is_active` (`plugins/webkul/plugin-manager/src/Package.php:270-295`). Setting the flag false would not reliably disable routes, UI, listeners, or jobs.

**Uninstall is destructive and unsafe for posted logistics history.**

- CLI uninstall calls each declared migration/setting `down()`, removes migration ledger entries, deletes the plugin row, and refreshes caches (`plugins/webkul/plugin-manager/src/Console/Commands/UninstallCommand.php:34-133`).
- Filament warns about detected non-empty create-table migrations but does not block removal (`PluginsTable.php:181-230`), then runs migration/setting `down()` and marks the plugin uninstalled (`:238-304`, `:330-348`). Its detection misses shared-table alterations and nonstandard migration names.
- Existing modules demonstrate intentional operational deletion: Inventory deletes operations, moves, quantities, routes, and locations in its uninstall hook (`plugins/webkul/inventories/src/InventoryServiceProvider.php:131-159`); Manufacturing deletes/force-deletes inventory structures (`plugins/webkul/manufacturing/src/ManufacturingServiceProvider.php:110-221`).

Required protection:

1. The logistics uninstall `startWith` hook must fail closed if any posted dispatch, handover, receipt, exception, approved charge, advance, settlement link, correction, reversal, or evidence exists.
2. Transaction-history migrations must not have destructive production `down()` behavior after release; schema evolution is forward-only.
3. Empty/never-used uninstall, if allowed, must be a server-side invariant, not a warning modal.
4. Operational suspension must be a separate `accept_new_activity` setting enforced by every command/service and background entry point. A generic Plugin Manager disable patch is a separate approval.
5. Tests must prove a blocked uninstall preserves flags, migration ledger, and rows, and that suspended mode permits history access/corrections while rejecting new planning.

## 3. Source ownership and reuse findings

The detailed capability table is in `docs/logistics/REUSE-MAP.md`. The important conclusions are:

### Product, UoM, variants, and packaging

- Products already own sellable/storable identity, variants, price/cost, stock UoM, purchase UoM, weight, and volume (`plugins/webkul/products/src/Models/Product.php:42-112`, `:119-176`). Variants are product rows linked by `parent_id` (`:164-171`, `:271-316`). **REUSE**.
- UoM conversion is category/factor based through `UOM::computeQuantity()` (`plugins/webkul/support/src/Models/UOM.php:88-136`). **REUSE for execution, unsafe as the sole historical record**.
- Product packaging is product- and company-specific and stores `qty` (`plugins/webkul/products/src/Models/Packaging.php:17-46`). Sales lines store packaging reference and packaging count alongside order UoM quantity (`plugins/webkul/sales/src/Models/OrderLine.php:42-89`, `:127-155`). **EXTEND**.
- The line keeps a foreign key to a mutable packaging row and UoM row, not a snapshot of the packaging/UoM conversion. Therefore the required historical 72/108 pouch-equivalent evidence cannot be reconstructed reliably after packaging/UoM changes. **UNSAFE TO REUSE WITHOUT CHANGE**.

Logistics must snapshot, per load line: source sales line, product/variant, entered package type/count, stock UoM, explicit numerator/denominator or base-quantity conversion, calculated stock quantity, and the label/reference used. The snapshot is evidence; stock still posts only in the product's native inventory UoM. Mixed-SKU containers require a parent load-container plus child content rows so the parent and contents are never both summed as physical product quantity.

### Partners, addresses, providers, drivers, and payees

Partners already model individuals/companies, contacts/addresses, customer/supplier roles, company ownership, and bank accounts. They can represent a corporate transporter, informal owner-driver, vehicle owner, driver, distributor, and payment recipient without forcing portal/email/app access. **REUSE identities; EXTEND role snapshots**.

One party must not be copied into every role implicitly. A booking needs explicit role assignments (contracted provider, vehicle owner, driver, authorized payee), each referencing a partner where available and retaining a display snapshot. Payee changes require authorization and must not rewrite posted advances or bills.

### Sales and inventory

- Sales lines expose direct source traceability to inventory moves via `sale_order_line_id` (`OrderLine.php:147-150`; `plugins/webkul/inventories/src/Models/Move.php:61-82`). **REUSE**.
- Confirmed sale lines trigger inventory procurement, including newly created/quantity-changed confirmed lines (`OrderLine.php:202-225`). **REUSE the workflow; do not create duplicate stock demand**.
- Inventory moves distinguish demanded `product_uom_qty`, completed `quantity`, reservation, lot/package, source/destination, returned origin, operation, and sale line (`Move.php:40-82`, `:156-233`). **REUSE**.
- Reservation performs UoM conversion and allocates from stock (`plugins/webkul/inventories/src/Services/MoveReserver.php:18-53`, `:175-237`). Completion and operation workflows are the stock-posting authority. **REUSE through service/facade**.
- Logistics needs an atomic allocation claim against eligible move availability to stop two planners allocating the same quantity. No logistics-level claim/version contract exists. **MISSING**.
- Native sales `qty_delivered` is derived from completed outgoing inventory moves less returns (`plugins/webkul/sales/src/Services/OrderCalculator.php:143-161`); it is stock execution, not distributor receipt evidence. **UNSAFE as receipt proof**.

The extension must link load allocations to existing sale lines and inventory move/operation lines. Actual loading may prepare/update the existing picking detail, but dispatch confirmation must call the inventory transfer workflow exactly once. A logistics status update must never decrement stock.

### Accounts payable, payment, and reconciliation

- Vendor bills, posted account moves, payment registration, partial reconciliation, and reversals already exist. API bill confirmation calls the accounting facade (`plugins/webkul/accounts/src/Http/Controllers/API/V1/BillController.php:258-287`); payment registration is transactional (`:314-381`); reversal uses the approved reverse-move flow and captures a reason (`:384-435`). **REUSE**.
- `PaymentRegistrar` builds payments with company, partner, journal, destination account, optional bank account, posts the payment move, then reconciles payable/receivable lines (`plugins/webkul/accounts/src/Services/PaymentRegistrar.php:159-230`, `:273-349`). **REUSE through existing workflow**.
- Freight agreement, charge approval, advance purpose, trip-to-bill linkage, and settlement holds are absent. **MISSING**.

Logistics should own approved freight components and immutable references to the resulting vendor bill/payment records. It should request bill/payment creation through a small accounting application service; it must not create move lines, mark bills paid, or treat an editable trip amount as an advance. The authorized payee must be the payment counterparty (or an explicitly approved legal mapping), and every advance must retain its payment and allocation references.

### Company/branch, authorization, attachments, activities, and numbering

- `BelongsToCompany` applies a company global scope and default company assignment (`plugins/webkul/support/src/Traits/BelongsToCompany.php:8-24`). `ChecksCompanyConsistency` validates selected cross-record links in web execution (`plugins/webkul/support/src/Traits/ChecksCompanyConsistency.php:7-47`). **REUSE, with explicit logistics consistency fields and service-level checks**.
- Users have a default company and allowed companies (`plugins/webkul/security/src/Models/User.php:107-126`). **REUSE**.
- Branches are child Company records (`plugins/webkul/support/src/Models/Company.php:33-57`, `:79-101`), not a separate tenancy model. Transactions carry `company_id`; Logistics must use the exact existing operating company/branch ID and must not invent `branch_id`. **REUSE**.
- Policies and `Gate::authorize()` are present across UI/API entry points. Logistics needs its own policies and Shield keys, and the same application service must authorize both Filament and any API/import entry point. **EXTEND**.
- Chatter activity is opt-in and swallows logging failures (`plugins/webkul/chatter/src/Traits/HasLogActivity.php:22-72`); attachments and stored files can be deleted (`plugins/webkul/chatter/src/Traits/HasChatter.php:601-643`; `plugins/webkul/chatter/src/Models/Attachment.php:58-75`). It is useful for human context but not sufficient for immutable dispatch/receipt/freight evidence. **UNSAFE as sole audit record**.
- Existing sequences/document references should be reused for numbering style, but local labels/prefixes and company/branch sequence rules are **unknown** and require approval.

## 4. High-risk integration flows

### Sale order to stock dispatch

Inspected flow:

1. UI/API creates or updates a sales order and lines; API write paths use requests, policy gates, and database transactions (`plugins/webkul/sales/src/Http/Controllers/API/V1/OrderController.php:90-176`).
2. Order confirmation delegates to `OrderWorkflow::confirm()` (`plugins/webkul/sales/src/Services/OrderWorkflow.php:43-73`).
3. Confirmed lines invoke inventory procurement; later confirmed-line creation or quantity changes invoke the same rules (`OrderLine.php:202-225`).
4. Procurement creates/updates inventory moves linked to the sales line; the sale-order listener recomputes delivered quantities from completed moves (`plugins/webkul/sales/src/Listeners/ComputeSaleOrderFromMoveListener.php:13-63`).
5. Inventory reservation uses `MoveReserver`; transfer validation/completion uses `TransferWorkflow` and move completion/cancellation services (`plugins/webkul/inventories/src/Services/TransferWorkflow.php:23-111`). Returned moves keep `origin_returned_move_id` (`Move.php:206-213`).

Source-review risks that must be hardened before Logistics relies on this flow:

- Filament confirmation/validation actions request database transactions, but corresponding Sales and Inventory API transitions do not consistently wrap the shared service call (`plugins/webkul/sales/src/Filament/Clusters/Orders/Resources/QuotationResource/Actions/ConfirmAction.php:14-55`; `plugins/webkul/sales/src/Http/Controllers/API/V1/OrderController.php:190-212`; `plugins/webkul/inventories/src/Http/Controllers/API/V1/DeliveryController.php:100-198`).
- `OrderWorkflow::confirm()` changes order state and then creates procurement/side effects without owning a transaction or lock (`plugins/webkul/sales/src/Services/OrderWorkflow.php:43-59`). A late failure or concurrent confirmation could leave partial or duplicate demand. **Inferred, not reproduced**.
- `TransferWorkflow` completion has no visible root row lock/idempotency key, while completion settles quantities, marks moves done, triggers downstream work, and creates backorders (`TransferWorkflow.php:64-93`; `plugins/webkul/inventories/src/Services/MoveCompleter.php:18-73`, `:76-144`, `:188-233`). Concurrent retries could double-apply side effects. **Inferred, not reproduced**.
- Reservation plans against availability before settlement; quant updates lock existing rows, but the product-quantity table has no canonical null-safe uniqueness constraint over the stock bucket (`plugins/webkul/inventories/src/Services/MoveReserver.php:18-69`, `:175-219`; `plugins/webkul/inventories/src/Models/ProductQuantity.php:289-338`, `:498-595`; `plugins/webkul/inventories/database/migrations/2025_01_14_113233_create_inventories_product_quantities_table.php:14-68`). Parallel planners may over-reserve or create duplicate buckets. **Inferred, not reproduced**.
- Existing reservation competition tests are sequential, not two-connection concurrency proofs (`plugins/webkul/inventories/tests/Feature/Workflows/OneStepDeliveryTest.php:307-318`; `plugins/webkul/inventories/tests/Feature/Workflows/ReplicationTest.php:205-250`).

Boundary:

- Ready-to-dispatch demand is a read model over confirmed sales lines and their existing delivery moves, not a copied order backlog.
- Planning claims must be transactionally locked against available eligible move quantity and uniquely constrained per allocation/version.
- Actual load is written to existing move lines in their expected UoM/lot/package structure through an upstream service contract.
- A dispatch command calls the transfer workflow once and stores the resulting operation/move references and posting time. Use an idempotency key unique to the logistics dispatch attempt.
- Driver/carrier handover is a separate logistics event after stock posting; delivery reports and distributor receipt do not post stock again.
- Rejected/returned goods use existing return/reversal operations. Never reduce the original sale order silently or fabricate a corrective stock move.

Smallest upstream-facing inventory patch: make Sales confirmation and Inventory reserve/complete/cancel/return transitions transaction-owning and idempotent. Reload and `lockForUpdate()` the transition root, validate state inside the lock, serialize the relevant stock buckets, enforce a canonical quant identity, and return the already-created result on an identical retry. Expose a narrow `DispatchOperationGateway` that applies actual move lines and calls those hardened workflows. Tests must prove retry idempotency, concurrent confirmation/allocation/completion exclusion, rollback after late failure, partial dispatch/backorder behavior, lot/package traceability, and company isolation.

### Supplier obligation to payment

Inspected flow:

1. A vendor bill is created in draft through the accounts domain.
2. Confirmation authorizes update, checks draft state, and calls `AccountFacade::confirmMove()` (`BillController.php:258-287`).
3. Payment requires a posted bill with an outstanding payment state, builds a `PaymentRegister`, attaches outstanding term lines, and calls the account workflow inside a transaction (`BillController.php:314-381`).
4. `PaymentRegistrar` creates/posts payment journal entries and reconciles outstanding payable lines (`PaymentRegistrar.php:189-230`, `:273-349`).
5. Corrections use bill/account-move reversal with reason/date/journal (`BillController.php:384-435`; `plugins/webkul/accounts/src/Services/MoveWorkflow.php:125-198`).
6. The custom bank-reconciliation layer matches posted accounting evidence; it is not the bill/payment creation path.

The custom reconciliation implementation is split across root `app/Models/Recon`, `app/Services/Recon`, `app/Filament/Pages/Recon`, root migrations, and Accounts-owned migrations/provider declarations. Root pages are registered in `app/Providers/Filament/AdminPanelProvider.php:31-45`, while Accounts declares reconciliation migrations in `plugins/webkul/accounts/src/AccountServiceProvider.php:130-147`. This is a modified-fork integration boundary, not a clean plugin reference. Logistics must keep all of its owned classes/migrations in its plugin and reuse reconciliation only downstream of posted Accounts evidence.

Normal AP payment is less strongly guarded than custom reconciliation. `PaymentRegistrar` posts/reconciles, but normal residual mutation has no visible outstanding-line lock, the API request accepts globally existing journal/method/bank IDs, and no source idempotency key exists (`plugins/webkul/accounts/src/Services/Reconciler.php:248-300`, `:685-725`; `plugins/webkul/accounts/src/Http/Requests/MovePaymentRequest.php:8-34`; `BillController.php:500-537`). Repeated/concurrent freight settlement could create duplicate attempts or accept an inappropriate cross-company reference. **Inferred, not reproduced**.

Boundary:

- Approved freight charges create or attach to one vendor bill through Accounts.
- Advances create referenced payments against the approved counterparty and remain allocated to the freight agreement/settlement; later settlement reconciles rather than overwrites the advance.
- Extra charges remain proposed/approved/rejected independently from transport progress.
- Logistics records external accounting IDs and state snapshots for display; Accounts remains authoritative.
- Retry keys and a unique logistics-origin reference must prevent duplicate bills/payments.
- Reversal/correction commands call Accounts and append logistics correction evidence; they never edit posted totals.

Smallest upstream-facing accounting patch: a transaction-owning application service accepting the exact operating company, counterparty, approved freight lines, source document reference, and idempotency key. It must lock the source obligation and outstanding payable lines, enforce company/ownership consistency across bill, journal, method and bank, persist unique source provenance, return the existing result on an identical retry, reject changed intent under a reused key, and call only existing move/payment workflows. Regression tests must cover concurrent/duplicate submission, wrong-company parties/accounts, partial advance, authorized bank recipient, bill reversal, payment reconciliation, and no side effects on rejection.

### UI and non-UI parity

Filament callbacks may collect intent, but business decisions and posting must live in shared application services. API controllers already demonstrate Form Request + `Gate::authorize()` + domain facade/service patterns. Every Filament action, API endpoint, import, or future job must call the same logistics command handlers. Direct model status updates are not equivalent to confirmation, reservation, transfer validation, bill posting, payment posting, or reversal.

## 5. Proposed implementation boundary

### Extension-owned concepts

Keep the first schema small but explicit:

- **Dispatch plan**: exact existing operating company/branch ID, state, source demand horizon, planner, version.
- **Load allocation**: source sales line and inventory move/operation, planned quantity, stock UoM, packaging/conversion snapshot, allocation state.
- **Transport booking/trip**: mode, provider/owner/driver/payee role links and snapshots, vehicle body/nominal length/usable dimensions/payload as distinct nullable facts, agreed freight, planned/actual dates.
- **Stops and handovers**: ordered ship-to/distributor references, planned/actual quantities, carrier handover.
- **Transport events**: event type/time, reporter identity/role/contact method, recorder user/time, evidence state and attachments. Driver-reported delivery is never the verified-receipt state.
- **Receipt/discrepancy**: accepted, rejected, and unresolved quantities per dispatched line, verifier/evidence, reason and resolution references.
- **Freight charges and settlement links**: agreed/additional components, approval state, holds, vendor bill, advance/payment, reconciliation, and reversal references.

Do not create new product, partner, stock-quantity, journal-entry, vendor-bill, or payment masters.

### State separation

At minimum, keep these dimensions separate:

| Dimension | Example states |
|---|---|
| Planning | draft, allocated, released, cancelled |
| Stock execution | awaiting pick, loaded, inventory posted, reversed |
| Transport | booked, handed over, in transit, reported delivered, missed/failed |
| Receipt evidence | unverified, distributor verified, disputed |
| Discrepancy | none, unresolved, accepted shortage/damage, return pending, resolved |
| Freight | proposed, approved, held, billed, partly paid, settled, reversed |

Unknown is represented by `null`/an explicit unknown state, not zero. Vehicle nominal length must not infer usable dimensions or payload.

### Minimal code structure for a later authorized task

```text
plugins/shafique/logistics/
├── composer.json
├── config/filament-shield.php
├── database/migrations/
├── resources/lang/en/
├── src/LogisticsServiceProvider.php
├── src/LogisticsPlugin.php
├── src/Models/
├── src/Policies/
├── src/Services/            # commands/gateways; business rules live here
└── tests/Feature/
```

Add routes or assets only if an approved use case needs them. An admin-only Filament surface is sufficient for the coordinator-led first slice; no driver/customer panel is required.

### Mandatory database/service invariants

- Snapshot product/packing conversion on every released/actual load line; never recalculate old evidence from current packaging.
- Store parent physical container and SKU contents separately and define one authoritative quantity aggregation path.
- Use nullable capacity fields plus source/confidence; never coerce unknown to zero.
- Lock demand/moves and enforce a unique active allocation constraint so planners cannot over-allocate.
- Store a unique idempotency key and canonical stock operation reference per dispatch posting.
- Maintain planned, dispatched, accepted, rejected, unresolved, and returned quantities separately.
- Store reporter, recorder, event time, recorded time, channel, and evidence state on phone reports.
- Require an explicit verifier/evidence transition for distributor receipt.
- Reference every advance to the counterparty, payment, freight agreement, and later allocation/settlement.
- Posted corrections are append-only logistics events plus approved Inventory/Accounts reversals.
- Refuse uninstall when posted/history evidence exists.

## 6. Recommended first end-to-end slice

**Recommended default:** one dedicated hired trip, one existing confirmed sales delivery operation, one distributor stop, coordinator-entered updates, and one third-party freight counterparty. Support multiple product lines, partial actual loading, one advance, one final approved freight bill, receipt verification, discrepancy capture, and retry-safe posting.

Sequence:

1. Select eligible confirmed sales delivery moves.
2. Atomically claim planned quantities and snapshot packaging conversion.
3. Record provider/owner/driver/payee roles and vehicle facts without mandatory digital access.
4. Verify actual quantities/lots/packages and post the existing inventory delivery exactly once.
5. Record carrier handover and later phone reports with reporter/recorder separation.
6. Record distributor-verified receipt or unresolved discrepancy without a second stock posting.
7. Approve freight, create/reference the native vendor bill and advance/payment, then settle through Accounts and bank reconciliation.
8. Correct only through existing return/reversal mechanisms with appended logistics evidence.

Why this slice: it proves every dangerous boundary—quantity history, allocation concurrency, stock idempotency, human evidence, receipt semantics, AP/payment linkage, and uninstall retention—without the extra branching of multi-stop or transporter consignment aggregation.

Add multi-stop dedicated trips next. Add goods-transporter consignments when the owner confirms consignment document/charge/receipt rules and they represent initial-release volume. Add distributor-arranged pickup as a booking mode with no company freight liability once responsibility, handover evidence, and exception ownership are approved.

## 7. Open decisions requiring human approval

| Decision | Impact | Recommended default pending approval | What changes the recommendation |
|---|---|---|---|
| Stock UoM and bundle SKU policy | Conversion, pricing, stock posting | Keep current product stock UoM authoritative; treat packaging as an explicit snapshot, not a new SKU | If bundles are separately priced/tracked sellable stock, model them as approved variants/SKUs |
| Mixed-SKU cartons/bundles | Load structure and counting | Disallow mixed contents in first slice | Routine mixed packing requires container/content rows and scan/manual controls |
| Loose packets/open bundles/partial cartons | Quantity entry and discrepancy | Allow partial stock-UoM quantities only where current UoM rounding permits; require reason | Warehouse evidence that routine dispatch needs alternate partial-pack units |
| Partial-load approval | Workflow/permissions | Warehouse verifies actual; logistics supervisor approves variance | Existing role matrix assigns another accountable role |
| Receiving discrepancy approval | Returns/claims | Distributor verifier records evidence; sales/logistics supervisor resolves; Inventory posts returns | Contract/customer policy requires finance or QA approval |
| Extra freight approval | AP control | Separate logistics proposer and finance approver | Low-value threshold policy approves controlled delegation |
| Manual corrections | Audit and reversals | Supervisor permission plus mandatory reason; posted corrections use native reversal | Approved maker-checker matrix specifies stricter separation |
| Verified receipt evidence | Revenue/settlement holds | Named distributor representative plus coordinator-recorded call/document evidence; driver report remains unverified | Signed POD, stamp, or distributor system confirmation becomes mandatory/available |
| Freight settlement holds | Payment timing | Hold final settlement on unresolved delivery discrepancy; advance remains separately payable only if approved | Contracts allow payment regardless of shortage/damage |
| Initial transport modes | Schema/UI branching | First slice dedicated hire; then multi-stop; defer consignment and pickup policy branches | Owner confirms transporter consignment or pickup is required at go-live |
| Unfulfilled quantities | Order/backorder semantics | Let native inventory backorder/open delivery represent remainder; do not rewrite order | Business explicitly requires cancellation/new order workflow |
| Exact stock trigger | Idempotency boundary | Native inventory transfer validation only | Source-backed approval establishes another canonical posting event |
| Exact accounting trigger | Liability timing | Approved freight creates/posts native vendor bill; payment only through payment workflow | Accounting policy requires accrual at booking/handover |
| Local labels/language/numbering | Documents and adoption | English admin UI plus configurable local labels; company-scoped sequence | Urdu labels, transporter bilty terminology, branch prefixes, or statutory format are approved |
| Operating company/branch | Access and numbering | Use the exact child Company ID already used by source orders, stock and accounting; add no `branch_id` | Business identifies a separate non-Company operational hierarchy |
| Provider/payee eligibility | Fraud control | Partner record plus explicit role and verified bank recipient where electronic payment requires it | Cash/cheque controls or informal-payee documentation require a different approval path |

## 8. Test plan and baseline

### Existing regression boundaries to retain

- Sales: `plugins/webkul/sales/tests/Feature/Workflows/{OneStepSaleOrderTest,TwoStepsSaleOrderTest,ThreeStepsSaleOrderTest,SaleOrderTest}.php`, API order/delivery tests, and company scoping/isolation tests.
- Inventory: one/two/three-step delivery, operation change, quantity, company isolation/scoping, API Delivery/Move/Lot/Package tests under `plugins/webkul/inventories/tests/Feature`.
- Accounts: VendorBill, MoveLifecycle, PaymentRegisterBankAccount, CompanyIsolation, CompanyScopingInvariants, API Bill tests under `plugins/webkul/accounts/tests/Feature`.
- Purchases: order billing/receipt tests where transporter/vendor bill behavior shares partner/AP contracts.
- Bank reconciliation: 21 tests under `tests/Feature/Recon`, especially `MatchAccountingIntegrationTest`, `AutoMatchAtomicityTest`, `ReconciliationControlsTest`, `LifecycleTest`, `AdjustmentServiceTest`, and `ReconciliationSchemaTest`.

### Required logistics tests before implementation can be accepted

1. product-specific 72/108-style conversions and immutable historical snapshots after packaging changes;
2. no parent-container/content double count;
3. unknown vehicle capacity remains unknown;
4. concurrent planners cannot claim the same move quantity;
5. repeated load/dispatch/receipt submissions cannot double-post stock;
6. partial load creates the approved native remainder/backorder behavior;
7. planned/dispatched/accepted/rejected/unresolved quantities reconcile but remain distinct;
8. reporter/recorder and event/recording times are preserved;
9. driver-reported delivery cannot transition verified receipt;
10. wrong-company/unauthorized source, partner, vehicle, bill, bank, or payment references fail without side effects;
11. advance, bill, payment, allocation, reconciliation, and reversal links survive corrections;
12. provider/vehicle replacement appends history rather than rewriting released evidence;
13. uninstall/disable protections preserve posted history;
14. Filament and API/service entry points produce identical domain effects;
15. MySQL 8 and PostgreSQL 16 both pass in disposable CI databases.

### Commands executed and results

All commands were read-only except creation/editing of these two Markdown files.

- Git inspection: `git branch --show-current`, `git rev-parse`, `git status --short --branch`, `git remote -v`, `git branch -vv --no-abbrev`, `git merge-base`, `git log`, and name-only diffs. Result: baseline recorded above; initially clean.
- Repository/source inspection: `pwd`, `rg --files`, `rg -n`, `find`, `sed -n`, `nl -ba`, `jq`. Result: findings and line references above.
- Runtime/package inspection: `php -v`, `composer --version`, selected manifest/lock/installed metadata reads, `node --version`, and `npm --version`. Result: version table above.
- `composer validate --no-check-publish`. Result: passed with one warning that the exact `bezhansalleh/filament-shield` constraint is discouraged.
- `vendor/bin/pint --dirty --format agent`. Result: passed; no application files were changed by the audit.
- `git diff --check` plus document existence/count and secret-pattern checks. Result: passed.

### Tests and operational commands not run

Not run: Artisan commands, Pest/PHPUnit/Playwright tests, migrations, database resets, install/uninstall commands, seeders, cache clears, Composer install/update/dump-autoload, npm install/build, database queries, or browser workflows. The local Pest database was not proven disposable, and this task did not authorize operational mutation.

## 9. Approval gates and next steps

Human approval is required before:

1. choosing initial transport modes and the open business policies above;
2. approving the extension name/vendor/namespace and the small Plugin Manager test-bootstrap patch;
3. approving the non-destructive suspension/uninstall contract;
4. defining the exact Inventory and Accounts gateway contracts and idempotency keys;
5. creating an isolated disposable MySQL/PostgreSQL test environment;
6. authorizing schema, models, migrations, routes, screens, ACL, or posting behavior.

Recommended next step: hold a short business/technical decision workshop using the open-decision table, then write an approved implementation plan for only the first slice and its upstream contracts.
