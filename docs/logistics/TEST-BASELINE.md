# Logistics Phase 1 Test Baseline

Document role: dated execution evidence. A passing result proves only the named behavior, code state, database engine, fixtures, and entry points. It does not approve business policy, prove operational readiness, or replace the target plan under [`plan/`](plan/README.md).

These historical pass counts do not satisfy V1-V10 or AE1-AE30 in [`plan/09-TESTING-AND-ACCEPTANCE.md`](plan/09-TESTING-AND-ACCEPTANCE.md). PostgreSQL-only results do not satisfy a cross-engine gate. Every implementation phase must record its own current revision, local changes, database engine, fixtures, commands and observed results. Evidence explicitly marked superseded remains non-current.

Status: engineering verification evidence at 2026-09-15 (Asia/Karachi). This is not production-module approval. `inspected` means source read, `tested` means the named command ran against the disposable database, and `unknown` means no executable proof was obtained.

## Phase 2B current-status addendum — 2026-09-19

- Non-posting `plugins/shafique/logistics` foundation implemented locally at unchanged HEAD `3807c2d498b0c3e609f6884d764b0518e38e30a8`. Fresh disposable PostgreSQL install/reinstall, native settings action/permissions, exact Company isolation, control-history rollback, and CLI/UI removal guards passed. No operational installation. `[tested]`
- Final explicit foundation/removal/bootstrap selection: **22 passed / 166 assertions**, 40.49s. Unchanged Phase 2A runner default selection: **177 passed / 516 assertions**, 103.90s. No skips or failures. The existing runner, Sales/Inventory production repairs and lockfile retain their pre-task content hashes. `[tested/inspected]`
- Exact commands, source groups, lifecycle limits, preserved local work and operator walkthrough: [PHASE-2B-IMPLEMENTATION-REPORT.md](PHASE-2B-IMPLEMENTATION-REPORT.md). MySQL, browser visual review, full suite, known Recon failures, Inventory transition gates and Accounts policies remain unpassed. The foundation does not post stock or money and does not prove future workflows. `[not proven]`

## Phase 2A current-status addendum — 2026-09-19

- Current branch/HEAD: `master` at `3807c2d498b0c3e609f6884d764b0518e38e30a8`, 10 commits ahead of `origin/master`. The Sales and Inventory production repairs are local/uncommitted changes; this repository provides no evidence that they were approved or merged. The older baseline below remains historical evidence. `[inspected]`
- Installed runtime remains PHP `8.5.0`, Laravel `13.21.1`, Filament `5.7.6`, Livewire `4.3.3`, Pest `4.7.5`, PHPUnit `12.5.30`, Laravel Boost `2.4.13`, and PostgreSQL `18.1`. Current `AGENTS.md` still contains a stale Laravel 11/Filament 4/PHP 8.3.21 block in another contributor's modified file; it was not overwritten in this task. `[inspected]`
- `scripts/run_logistics_phase2a_tests.sh` now provisions a fresh UTF-8 private PostgreSQL cluster and unique `_testing` database on every run, uses task-owned storage/cache/socket paths and synthetic credentials, disables delivering mail/SMS destinations, verifies raw PostgreSQL and effective Laravel database/storage configuration, applies PHP `memory_limit=512M`, and cleans up only a marker-validated task root. `[tested]`
- Sales confirmation remains state-transition replay safe at the shared `OrderWorkflow::confirm()` boundary. It has no persisted request key or payload fingerprint, so request-level idempotency is still proposed rather than implemented. `[source-inspected; selected rerun recorded below]`
- Inventory Phase 2A is narrowed to `TransferWorkflow::createReturn()`. Completion/cancellation locking, replay, and concurrency claims in the older follow-up section are superseded and remain item 5B. `[inspected]`
- The current return repair validates empty, foreign-source, malformed, infinite, zero, and negative quantities before writes; wraps reservation release, return operation/moves, reserve, cross-links, and synchronous database event work in the source operation connection transaction; and preserves explicit partial/full return traceability. `[tested]`
- The rollback assertions prove persisted database state on the configured single connection. The tested late `OperationReturned` failure now occurs before Chatter message creation and schedules no follower callback. Alternate Eloquent connections and the general after-commit behavior of Chatter callbacks created before other rollback causes remain unproven. `[tested and source-inspected; unresolved shared boundary]`
- The current return API still has no quantity-map request contract. A done-operation request therefore produces the existing server-error response, but now leaves no orphan operation. Automatic full return and a new API parameter were deliberately not invented. `[tested; product/API decision unresolved]`
- PostgreSQL-focused run after final runner hardening: `TransferIntegrityTest.php` plus Inventory API `DeliveryTest.php` passed 29 tests / 64 assertions. A first 12-file selected run passed 176 tests and failed one Sales API test because the initial reusable runner created `SQL_ASCII`; this was an infrastructure defect, not an application assertion. The runner was corrected to `--encoding=UTF8`, the previously failing Sales API file passed 17 tests / 63 assertions, and a fresh final 12-file run passed 177 tests / 515 assertions in 124.33 seconds. `[tested]`
- MySQL, the full repository suite, browser/E2E, the known failing Recon suites, cumulative concurrent returns, completion/cancel conflicts, and Accounts advance/payee behavior were not executed in Phase 2A. `[not proven]`

## Repository baseline

- Branch: `master`.
- Commit: `45957cb763369e07ba46c702c170c09dab7b5a35`.
- Upstream: `master` is 9 commits ahead of `origin/master` and 0 behind.
- Audited commit: `6d1f0db07ca3bd57455c11d06bb64151f59cc83f`, the direct parent of the current commit.
- Relevant delta: the one commit since the audit changes only 15 bank-reconciliation documentation/ADR files. No Sales, Inventory, Accounts, Purchases, plugin lifecycle, or test-bootstrap source changed in that commit. `[inspected]`
- Pre-existing working tree retained: modified `AGENTS.md`; untracked `docs/logistics/`. No unrelated file was reverted or normalized.

| Component | Manifest constraint | Locked | Installed/runtime |
| --- | --- | --- | --- |
| PHP | `^8.3` | n/a | `8.5.0` |
| Laravel | `^13.0` | `13.21.1` | `13.21.1` |
| Filament | `^5.0` | `5.7.6` | `5.7.6` |
| Livewire | transitive | `4.3.3` | `4.3.3` |
| Sanctum | `^4.0` | `4.3.3` | `4.3.3` |
| Pest | `^4.0` | `4.7.5` | `4.7.5` |
| PHPUnit | transitive | `12.5.30` | `12.5.30` |
| Laravel Boost | `^2.0` | `2.4.13` | `2.4.13` |
| PostgreSQL client/server used | n/a | n/a | Homebrew `18.1` |
| Composer / Node / npm | n/a | n/a | `2.8.12` / `24.14.0` / `11.12.1` |

The live `AGENTS.md` version block now names Laravel 13, Filament 5, Livewire 4, Pest 4, and PHPUnit 12, but still says PHP `8.3.21`; the actual CLI is PHP `8.5.0`. The narrow correction is to describe PHP as manifest `^8.3`, tested runtime `8.5.0`, rather than pinning a stale local patch version. Do not overwrite the concurrent `AGENTS.md` edit without its owner reviewing that one-line correction.

## Isolation proof

A fresh PostgreSQL cluster was created under a unique `mktemp` directory. It used a new data directory, a mode-0700 Unix-socket directory, `listen_addresses=''` (no TCP listener), PostgreSQL local `trust` authentication inside that private directory, test-only role `logistics_phase1`, and database `aureuserp_logistics_20260914204200_testing`. No existing server, database, volume, or business records were read. `[tested]`

Laravel was booted before destructive test bootstrap and reported exactly:

- environment `testing`;
- driver `pgsql`, the unique database above, private socket, and port `55439`;
- task-owned `LARAVEL_STORAGE_PATH` under the same temporary root;
- array cache/session/mail, sync queue, local filesystem, null/standard-error logging;
- task-owned config, package, services, routes, events, and compiled-view cache paths.

The application key was synthetic. No operational `.env` was edited. Docker was not used because its daemon was unavailable. The existing local `aureuserp_testing` database was not used because its ownership and disposability were unproven.

After evidence capture, PostgreSQL was stopped with `pg_ctl ... stop -m fast` and the validated task root `/tmp/aureuserp-logistics-phase1.bG3Uv4` was removed. That deleted only this task's synthetic database, caches, and storage; the temporary fixtures are not recoverable. `[tested]`

## Bootstrap findings and permitted changes

The first clean run failed all 22 selected Sales tests before any assertion: root migration `database/migrations/2026_09_11_000001_create_recon_bank_opening_balances_table.php:12-20` referenced Accounts tables before the plugin installer created them. `TestBootstrapHelper::ensureERPInstalled()` runs `migrate:fresh` before `erp:install` (`plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php:224-258`). `[reproduced]`

The test-only repair:

1. requires every non-parallel target database name to end in `_testing`, regardless of `APP_ENV`;
2. preloads only Product and Accounts migration paths needed by root reconciliation migrations, while preserving normal timestamp ordering;
3. still runs the plugin installer unless the plugin row and its probe table both prove installation, so settings and seeders are not skipped;
4. resolves plugin roots under `plugins/*/<name>` and filters migration paths without assuming vendor `webkul`;
5. includes a focused test proving both `plugins/webkul/accounts` and `plugins/shafique/logistics`-shaped fixture roots resolve.

An intermediate run exposed why table existence alone was insufficient: Accounts tables existed but settings did not, producing eight `MissingSettings` errors. The final installer check uses both plugin state and table state. `[reproduced and corrected in test infrastructure]`

`PaymentRegisterBankAccountTest` also created a bank account that violated the current company ownership, currency, source, and one-owner constraints. The fixture now creates a company-owned bank account (`partner_id=null`, matching `company_id`/`currency_id`, `source_type=manual`). The production validation was not bypassed. `[reproduced and corrected in test code]`

## Executed results

| Area / command target | Result | Classification |
| --- | --- | --- |
| `TestBootstrapPluginPathTest.php` on a fresh DB | 1 passed, 2 assertions | bootstrap supported after patch |
| `VendorBillTest.php` on a fresh DB | 8 passed, 16 assertions | native posted/partial/reversal baseline supported within test scope |
| `OneStepSaleOrderTest.php` | 22 passed, 49 assertions | normal sequential Sales-to-delivery baseline supported |
| Sales API `OrderTest.php` | 17 passed, 62 assertions | API baseline supported with 512 MiB runner |
| `OneStepDeliveryTest.php` | 65 passed, 242 assertions | normal reserve/partial/backorder/return baseline supported |
| `ReplicationTest.php` | 20 passed, 40 assertions | sequential competing/replication cases supported; not concurrency proof |
| Inventory API `DeliveryTest.php` | 16 passed, 27 assertions | API state/authorization baseline supported with 512 MiB runner |
| Accounts API `BillTest.php` | 30 passed, 78 assertions | bill API baseline supported with 512 MiB runner |
| `PaymentRegisterBankAccountTest.php` | initially 2 failed/3 passed; after fixture correction 5 passed, 11 assertions | test fixture repaired; no different-recipient proof |
| Purchases `OrderBillingTest.php` | 7 passed, 17 assertions | supplier obligation-to-bill baseline supported |
| Recon `ReconciliationControlsTest.php` | 11 passed, 66 assertions | selected controls baseline supported |
| Recon `MatchAccountingIntegrationTest.php` | 19 failed, 4 passed, 20 assertions | pre-existing regression boundary failing: fixtures/expectations conflict with same-direction guard at `MatchingEngine.php:247-250` |
| Recon `AutoMatchAtomicityTest.php` | 2 failed, 7 passed, 29 assertions | failures occur at same-direction guard before expected injected failure |
| Recon `MatchConcurrencyTest.php` | 1 failed, 4 assertions; zero of two workers succeeded | independent-process test ran, but did not reach expected one-success/one-conflict result |
| New Sales late-failure specification | 1 failed, 2 assertions | **reproduced defect**: injected `OrderConfirmed` failure left order in `SALE` rather than `DRAFT` |
| Sales confirmation atomicity/replay follow-up | 2 passed, 5 assertions | late listener failure now rolls back; a sequential confirmed-order replay creates no second operation |
| Sales confirmation independent-process follow-up | 1 passed, 7 assertions | two PostgreSQL workers both complete, with one persisted procurement operation |
| Inventory transfer atomicity follow-up | 4 passed, 15 assertions | empty returns create no operation; completion/cancellation listener failures roll back; completion retry posts stock once |
| Inventory completion independent-process follow-up | 1 passed, 7 assertions | two PostgreSQL workers both complete, with one stock posting |
| New Inventory empty-return specification | 1 failed, 1 assertion | **reproduced defect**: failed empty return increased operation count from 1 to 2 |
| Final bootstrap-path + Accounts-fixture rerun after formatting | 6 passed, 13 assertions | permitted test-only changes remain green |

The default 128 MiB PHP limit exhausted memory in API/Purchases runs while loading permissions or rendering synchronous Chatter email. Direct `vendor/bin/pest` runs with `php -d memory_limit=512M` passed the affected Sales, Inventory, Accounts, and Purchases files. Raising memory did not change assertions. It also confirms array mail prevents delivery but still renders and executes notification code synchronously.

## Risk-test evidence

### Sales confirmation late failure — reproduced defect

- Entry point/shared owner: `SaleManager::confirmSaleOrder()` to `OrderWorkflow::confirm()`; the service changes state, creates procurement, recomputes, then dispatches `OrderConfirmed` without owning a transaction (`plugins/webkul/sales/src/Services/OrderWorkflow.php:43-58`). API authorization and state checks occur outside that service (`plugins/webkul/sales/src/Http/Controllers/API/V1/OrderController.php:190-208`).
- Attempt: synthetic draft order with one line; a test-only final synchronous `OrderConfirmed` listener threw.
- Expected: original draft state and zero new operations/procurement effects.
- Observed: exception propagated and persisted order state was `SALE`. The first persisted-state assertion failed. This proves partial persistence for the shared service path; it does not prove every caller behaves identically.
- Smallest correction: make the common confirmation service transaction-owning, reload and lock the order, validate state under lock, and keep critical synchronous event work in that transaction. Move only explicitly optional, irreversible listeners after commit. Cover API, Filament, create-page, and confirmed-line model-hook callers.

### Sales confirmation atomicity/replay follow-up — tested-supported behavior

- `OrderWorkflow::confirm()` now owns a database transaction, reloads the order under `FOR UPDATE`, rejects terminal non-sale states under that lock, and returns a confirmed order without re-running procurement. The API delegates its state decision to that shared boundary; the targeted API, Filament action, create-page and confirmed-line workflow coverage passed.
- A fresh private PostgreSQL instance ran two independently bootstrapped Laravel workers against the same draft order. Both returned the same confirmed order and exactly one native procurement operation persisted.
- Ceiling: this is state-transition replay safety. The endpoint has no request id or payload, so it cannot yet distinguish a same-key retry from a different confirmation intent. Adding a Sales-owned request fingerprint requires an approved API contract and migration; it was intentionally not inferred here.

### Inventory empty return — reproduced defect

- Entry point/shared owner: API helpers call `Inventory::createReturn($operation)` without quantities; `TransferWorkflow::createReturn()` saves the replicated return at lines 123-126, then reserve fails because it has no moves at lines 132-134.
- Attempt: synthetic completed delivery, then the public facade with the API-default empty quantity map.
- Expected: explicit validation and no new operation.
- Observed: call failed and operation count changed from 1 to 2. The orphan persisted.
- Smallest correction: validate a typed, non-empty, source-bounded quantity map before creating the return and make the shared service transaction-owning. Define whether the API means explicit quantities or full return; do not guess.

### Inventory transfer atomicity follow-up — historical local evidence, superseded for current scope

- An earlier local draft wrapped completion, cancellation, and return creation and produced the listed test results. Phase 2A did not authorize that combined change, and the current implementation deliberately removed completion/cancellation modifications and the completion-concurrency test.
- Current supported behavior is limited to one return request's failure atomicity and pre-write input validation. Completion/cancel conflicts, successful-return replay, cumulative concurrent return limits, canonical quant uniqueness, and cross-engine behavior remain item 5B and are not proven.

## Commands

All repository commands were prefixed with `rtk`. The isolation commands were `mktemp -d`, `initdb --auth-local=trust --auth-host=reject`, `pg_ctl ... -o "-h '' -k <private-socket> -p 55439" start`, `createdb`, and `psql` identity/config checks. `<isolated-env>` below means the exact test-only Laravel/PostgreSQL variables listed under **Isolation proof**; no value came from the operational `.env`.

```text
<isolated-env> php artisan test --compact plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php
<isolated-env> php artisan test --compact plugins/webkul/accounts/tests/Feature/Workflows/VendorBillTest.php
<isolated-env> php artisan test --compact plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php
<isolated-env> php artisan test --compact plugins/webkul/inventories/tests/Feature/Workflows/OneStepDeliveryTest.php
<isolated-env> php artisan test --compact plugins/webkul/inventories/tests/Feature/Workflows/ReplicationTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/accounts/tests/Feature/API/V1/BillTest.php
<isolated-env> php artisan test --compact plugins/webkul/accounts/tests/Feature/Workflows/PaymentRegisterBankAccountTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/purchases/tests/Feature/Workflows/OrderBillingTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/Recon/ReconciliationControlsTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/Recon/MatchAccountingIntegrationTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/Recon/AutoMatchAtomicityTest.php
<isolated-env> AUREUSERP_RUN_CONCURRENCY_TEST=1 php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/Recon/MatchConcurrencyTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php
<isolated-env> AUREUSERP_RUN_SALES_CONFIRMATION_CONCURRENCY=1 php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php
<isolated-env> AUREUSERP_RUN_INVENTORY_COMPLETION_CONCURRENCY=1 php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/inventories/tests/Feature/Workflows/TransferCompletionConcurrencyTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php
<isolated-env> php -d memory_limit=512M vendor/bin/pest --compact plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php plugins/webkul/accounts/tests/Feature/Workflows/PaymentRegisterBankAccountTest.php
vendor/bin/pint --dirty --format agent
php -l plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php
php -l plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php
php -l plugins/webkul/accounts/tests/Feature/Workflows/PaymentRegisterBankAccountTest.php
php -l plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php
php -l plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php
php -l tests/Feature/Recon/MatchConcurrencyTest.php
git diff --check
git status --short --branch
git diff -- <changed test files>
```

Targets and results are enumerated in the table above. Version checks used `php --version`, `composer --version`, `composer show`, `node --version`, `npm --version`, `postgres --version`, and `initdb --version`. Git inspection used only status, log, branch, remote, and diff commands.

## Not run / not proven

- No MySQL execution; MySQL remains a required second-engine gate for any shared migration/locking change.
- No full repository suite, browser/E2E suite, load test, or production-like notification integration.
- No Inventory 60+60/40+60 reservation, transfer completion/cancel conflict, cumulative return, or Accounts duplicate payment proof. Those risks remain unresolved. Sales confirmation has PostgreSQL-only independent-process coverage; MySQL is still unproven.
- No pre-bill advance-then-bill allocation test and no different authorized-recipient test.
- No uninstall command was executed. Lifecycle conclusions remain source-inspected.
- No operational database, file storage, external SMS, bank endpoint, or real business fixture was accessed.
