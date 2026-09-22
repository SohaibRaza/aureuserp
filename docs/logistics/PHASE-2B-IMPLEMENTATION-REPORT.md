# Phase 2B implementation report

Document role: dated implementation and test evidence. This report does not define target business behavior, approve production use, or replace the consolidated plan under [`plan/`](plan/README.md).

Date: 2026-09-19. Scope: backlog item 8, non-posting Logistics foundation. Implemented locally; installed and exercised only in disposable PostgreSQL environments. No operational installation, commit, push, or deployment.

## Baseline and preservation

- Checkout: `master`, HEAD `3807c2d498b0c3e609f6884d764b0518e38e30a8`, ahead of `origin/master` by 10 commits.
- Locked and installed versions agree: Laravel `13.21.1`, Filament `5.7.6`, Livewire `4.3.3`, Pest `4.7.5`, PHPUnit `12.5.30`. Executed CLI: PHP `8.5.0`; disposable PostgreSQL `18.1`.
- Read current `AGENTS.md`, Phase 2B brief, Phase 2A report, current test addendum, contracts and backlog. The stale Laravel 11/Filament 4 instructions were not rewritten. Native plugin and Pest skills guided registration and executable acceptance tests. Installed source was the implementation baseline for this report; it was not treated as authority for business or domain behavior.
- Existing Sales/Inventory repairs, their tests, `config/testing.php`, the disposable runner, original audits, and `docs/logistics/plan/**` were not edited by this task. The initial index contained staged planning documents; a later read showed no staged files. This task ran no Git index-changing commands and did not reconstruct that external index change.
- Before/after SHA-1 content hashes match: Sales `OrderWorkflow.php` `9f599078aef22dea63e8456a1513d6378ee47482`; Sales API `OrderController.php` `bb08d1774d07fcdf6761a6128438a15e4288a792`; Inventory `TransferWorkflow.php` `c70a10ef01b1430ffe41d9e20689b74d49ccfd82`; runner `6def17c5b75a0ba15758675cb5e4ab5f72677354`; `composer.lock` `440770b2ed0e436e892bd62b3952ac7314868bf5`.

## Reviewable source groups

### 1. Logistics foundation

All new plugin files are under `plugins/shafique/logistics`:

- `composer.json`, `src/LogisticsServiceProvider.php`, `src/LogisticsPlugin.php`: native package discovery, install/migration declarations and admin-only registration. Root `bootstrap/providers.php` adds the provider; the existing Composer `plugins/*/*/composer.json` merge wildcard is unchanged. No dependency or lockfile upgrade.
- `config/filament-shield.php`, `src/Filament/Resources/ModuleSettingResource.php`, its `Pages/ModuleSettings.php`, `resources/views/module-settings.blade.php`, and `resources/lang/en/settings.php`: one native Module settings page, no CRUD operations or operational dashboard. Native role-editor permission keys are exactly `view_any_logistics_module::setting` and `update_logistics_module::setting`.
- `src/Services/OperationalControl.php`, `src/Models/CompanyControl.php`, `src/Models/ControlChange.php` and `database/migrations/2026_09_19_000001_create_logistics_company_controls_table.php`: the bounded control and history.
- `src/Listeners/PreventRemoval.php`: the installed-plugin removal veto, including inconsistent metadata protection.
- `tests/Feature/FoundationTest.php`, `tests/Feature/RemovalTest.php`: real package, service, native action and lifecycle acceptance. Root `phpunit.xml` adds the vendor-owned test suite; no bootstrap/runner rewrite.

Native reference: Barcode's provider/plugin and Plugin Manager's `PackageServiceProvider`, `InstallCommand`, `Plugin` metadata discovery and `PermissionManager`. Declared installable dependencies are `sales`, `inventories`, `accounts`, `products`; native Sales adds `invoices` and `payments` transitively. Partners is already a core ERP provider, not an invented `partners:install` dependency. No replacement masters or ledgers.

### 2. Shared Plugin Manager preflight

New `plugins/webkul/plugin-manager/src/Events/UninstallingPlugins.php` carries affected package names. `Console/Commands/UninstallCommand.php` dispatches before even the cached installed-state check. `Filament/Resources/PluginResource/Tables/PluginsTable.php::uninstallPlugin()` preflights its entire configured possible affected set before any destructive action. No bulk uninstall exists in this table.

Listeners veto with `DomainException`: CLI returns failure; UI displays an actionable persistent notification. Other failures propagate rather than authorize removal. The Logistics listener reads installation metadata directly, traverses declared and recorded dependencies, and blocks Logistics or any protected dependency while installed. Missing/false installed metadata with remaining control tables or migration marker also blocks removal. Suspension is the offered alternative. The existing CLI `--force` only skips confirmation; it cannot bypass this veto. No new bypass was added.

## Control, schema and lifecycle effects

Native settings were inspected, not assumed global: `CompanyAwareSettingsRepository` only scopes two Accounts groups and falls back to default/global values. Extending that accounting-specific mechanism would risk inheritance of an enabled flag. Instead:

- `logistics_company_controls`: unique native `company_id`, explicit boolean, timestamps. An absent row means suspended; install creates no per-Company rows and no backfilled approvals.
- `logistics_control_changes`: Company, actor, old/new boolean, trimmed required reason (1–1000 characters), recorded time. ORM update/delete is rejected; Company/user foreign keys restrict hard deletion. Direct SQL and database administrators remain outside this application-level guarantee.
- `OperationalControl::change()` checks installed state, view/manage permissions and the actor's exact native allowed Companies, then locks the Company row and writes the changed control plus history in one transaction. A same-state retry is a no-op. A history-write failure rolls back the control. Native bypass authorization remains available; parent access does not imply branch access.
- The page's Company ID comes from native `CompanyContext` and is Livewire-locked. Service checks are independent of button visibility. Selecting or tampering with another Company does not confer access.

| State | Start-new boundary | Read settings | Future existing-work completion/correction |
| --- | --- | --- | --- |
| Not installed | Rejected before control-table reads | Page/service rejected; discovery remains possible | Not implemented |
| Installed, suspended | `authorizeNewActivity()` rejects | Authorized access retained | Suspension must not blanket-deny; actual commands still deferred |
| Installed, active | Foundation access check passes for authorized Company | Authorized access retained | Actual commands still deferred |

Future workflows must call the shared guard **and** check their own workflow permissions/prerequisites. These tests do not prove a future dispatch service exists or calls it. No stock, Accounts, return API, freight, dispatch listener, scheduled job, driver login or transactional Logistics domain was added.

Installation and additive evolution use native `logistics:install --no-interaction` and `migrate --path=plugins/shafique/logistics/database/migrations --no-interaction`. Both were invoked in test subprocesses and repeated without duplicate metadata, dependencies, permissions or migration records. No invented upgrade command or cross-process atomic-DDL guarantee. Migration `down()` remains usable for authorized disposable development cleanup; supported application uninstall is vetoed. Arbitrary migration rollback, SQL, filesystem removal and future receipt-file retention are not protected by this foundation.

## Operator walkthrough

After a separately authorized deployment/install, assign the two Logistics permissions through native Roles and grant the required native Company access. In the admin panel, select the Company/branch using the existing Company context, then open **Logistics → Module settings**. It displays that Company, its active/suspended state, and the explicit non-posting limitation. **Suspend / resume new activity** requires a reason and manage permission. Newly encountered Companies start suspended. Enabling the control does not release dispatch or money functionality. Ordinary CLI/UI uninstall displays the protection message; suspend instead.

## Executed evidence

The unchanged runner created a new private PostgreSQL cluster, unique `_testing` database, test-only role, Unix socket with TCP disabled, and fresh storage/cache paths on every run. Its effective Laravel connection identity checks passed. Exit cleanup completed for task-owned resources only. No operational database or data was read. New page tests prevent stray Laravel HTTP requests and fake mail/notifications; this does **not** turn the runner into a general outbound-network sandbox.

| Run | Result |
| --- | --- |
| Initial missing-foundation red test | 1 failed / 1 assertion: provider absent, before implementation |
| First settings/install pass | 5 passed / 47 assertions, 27.04s |
| Expanded rollback/tamper pass | 7 passed / 63 assertions, 32.84s |
| Foundation + removal + existing bootstrap path test, intermediate | 19 passed / 142 assertions, 40.38s |
| Final foundation + removal + existing bootstrap path test | 22 passed / 166 assertions, 40.49s; no skips/failures; includes actual UI Logistics/Inventory/Products vetoes and false/missing metadata cases |
| Existing unchanged Phase 2A 12-file selection | 177 passed / 516 assertions, 103.90s; no skips or failures |

The 12-file selection covers the existing bootstrap vendor-path fixture, VendorBill, PaymentRegisterBankAccount, Purchase OrderBilling, OneStepSaleOrder, Sales API Order, confirmation integrity/concurrency, Inventory transfer integrity, OneStepDelivery, Replication and Delivery API. No pre-existing Plugin Manager PHP suite was found; the browser plugin-management suite was inspected by path but not run. No baseline functional regression was reproduced in this selection. Earlier Recon failures remain unresolved, not reclassified as passing.

Final isolated database identity: `aureuserp_20260919111617_61024_testing`; regression identity: `aureuserp_20260919111153_50146_testing`. Both clusters were task-owned and cleaned after successful exit. Lifecycle checks compare preserved flags, dependency links, full migration ledger, native seeded sentinel counts and control/history records. Unrelated Barcode CLI uninstall succeeds while the protected state remains intact. No bytes were attached or exercised; receipt-file retention is not claimed.

Reproduce the executed invocations from the repository root:

```bash
rtk composer dump-autoload --no-scripts
rtk scripts/run_logistics_phase2a_tests.sh plugins/shafique/logistics/tests/Feature/FoundationTest.php
rtk scripts/run_logistics_phase2a_tests.sh \
  plugins/shafique/logistics/tests/Feature/FoundationTest.php \
  plugins/shafique/logistics/tests/Feature/RemovalTest.php \
  plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php
rtk scripts/run_logistics_phase2a_tests.sh
rtk vendor/bin/pint --dirty --format agent plugins/shafique/logistics bootstrap/providers.php \
  plugins/webkul/plugin-manager/src/Console/Commands/UninstallCommand.php \
  plugins/webkul/plugin-manager/src/Filament/Resources/PluginResource/Tables/PluginsTable.php \
  plugins/webkul/plugin-manager/src/Events/UninstallingPlugins.php
rtk git diff --check
```

Autoload generation passed with existing unrelated PSR-4 warnings (TableView namespace, global test helpers/test exception classes); no Composer scripts/application bootstrap or dependency update was run by that command. Targeted Pint and PHP syntax checks passed. Only task-owned paths were formatted. The actual source is left in the working tree: new files are untracked, not merely a proposal embedded in this report. Review tracked integration changes with `git diff -- bootstrap/providers.php phpunit.xml plugins/webkul/plugin-manager`; view each new plugin file directly (or `git diff --no-index /dev/null <file>`). Nothing was staged by this task.

Syntax invocation: `for task_php_file in $(rtk rg --files plugins/shafique/logistics -g '*.php'); do rtk php -l "$task_php_file"; done`. An earlier `rtk find ... -exec` attempt was rejected by RTK's command wrapper before execution; the loop passed. There were no database infrastructure failures in these Phase 2B runs.

## Remaining gates and historical next step

The test limitations below remain evidence about this implementation snapshot. The final paragraph records the recommendation made at the time; it is superseded by [`plan/08-IMPLEMENTATION-ROADMAP.md`](plan/08-IMPLEMENTATION-ROADMAP.md). The foundation itself is subject to G2-G3 in [`plan/07-AUREUSERP-GAP-AND-REFACTOR-MAP.md`](plan/07-AUREUSERP-GAP-AND-REFACTOR-MAP.md), including serialized cutoff behavior and protection as operational records are introduced.

MySQL, Linux/CI portability, browser visual review, full-suite/Reconciliation recovery, real operational installation and concurrent settings-change stress tests were not run. Actual Livewire page/action rendering was tested; no screenshot is claimed. Guard assertions cover flags, migration ledger, dependency links, control/history and native synthetic sentinels—not nonexistent receipt files or an ERP-wide retention engine.

Keep the Phase 2A numeric-string successful Filament return case, current return-API HTTP 500 contract gap, later Chatter/alternate-connection effects, successful/cumulative return replay and completion/cancel concurrency explicitly open. Before claims, test A=40/B=60 with A dispatch/backorder identity changes; before packing snapshots, prove actual stock posting after master-data changes; before receipt, distinguish dispatched reconciliation basis from physically presented quantities. Accounts advance/recipient/recognition/tax/reversal/hold decisions remain blocked. Multi-stop and vehicle-unknown transporter bookings remain operational-release scope.

Historical recommendation: review the foundation, then take the smallest item 9 increment for native eligible-demand and quantity evidence. Current work must instead follow the consolidated roadmap. Allocation and posting remain blocked until the applicable Inventory and packaging gates are satisfied, and the foundation must be refactored where the canonical gap map requires it.
