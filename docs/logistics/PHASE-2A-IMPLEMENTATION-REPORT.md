# Logistics Phase 2A Implementation Report

Document role: dated implementation and test evidence. This report does not define target business behavior, approve production use, or replace the consolidated plan under [`plan/`](plan/README.md).

Date: 2026-09-19 (Asia/Karachi)  
Status: local review-ready changes; not committed, pushed, merged, or deployed

## Outcome

Phase 2A produced two bounded review units:

1. A reusable, fail-closed disposable PostgreSQL test runner and corrected current-status documentation.
2. A return-only Inventory database-atomicity repair at `TransferWorkflow::createReturn()` so invalid input or a late synchronous database failure leaves no partial persisted return effects.

The existing Sales confirmation implementation was inspected and rerun, not rebuilt. Completion/cancellation hardening, the full Logistics module, Accounts policy, and production migrations remain outside this change.

## Baseline and provenance

- Starting and ending HEAD: `3807c2d498b0c3e609f6884d764b0518e38e30a8` on `master`, 10 commits ahead of `origin/master` and 0 behind.
- Installed runtime: PHP `8.5.0`; Laravel `13.21.1`; Filament `5.7.6`; Livewire `4.3.3`; Pest `4.7.5`; PHPUnit `12.5.30`; Laravel Boost `2.4.13`; PostgreSQL `18.1`.
- Sales confirmation source/tests were already local and uncommitted at task start. The repository contains no commit/PR evidence approving them. Phase 2A preserved them and verified their shared-boundary behavior.
- The prior local Inventory draft also modified completion/cancellation and added a completion-concurrency test. Those changes exceeded this brief and were removed; only return failure atomicity remains.
- Pre-existing staged `docs/logistics/plan/**`, modified `AGENTS.md`, untracked `docs/.DS_Store`, and other unrelated changes were not normalized or reverted.
- `AGENTS.md` currently contains a stale Laravel 11/Filament 4/PHP 8.3.21 version block while installed/locked versions are newer. Because that file was concurrently modified, this task records rather than overwrites the mismatch.

## Reviewable change set A — isolated runner and status

Files:

- `scripts/run_logistics_phase2a_tests.sh` — new executable runner.
- `config/testing.php` — the pre-existing local Sales-concurrency flag was preserved; the out-of-scope Inventory-completion flag was removed.
- `docs/logistics/TEST-BASELINE.md` — Phase 2A evidence addendum and superseded Inventory overclaim.
- `docs/logistics/INTEGRATION-CONTRACTS.md` — implemented Sales/return behavior separated from proposed idempotency/cumulative-return contracts.
- `docs/logistics/PHASE-2-BACKLOG.md` — item 5 split into implemented-local 5A and unimplemented 5B; stale Sales next-task instruction removed.

The runner:

- creates a fresh UTF-8 PostgreSQL cluster and unique database ending `_testing` under a short task-owned `/private/tmp` path;
- uses a private mode-0700 Unix socket with `listen_addresses=''`, task-specific role/database, synthetic application key, isolated Laravel storage/cache/view paths, array cache/session/mail, local filesystem, null logging, sync queue, and blank SMS credentials;
- verifies server data directory/no-TCP identity plus database/role identity, then boots Laravel and verifies its effective environment, connection, socket, storage, cache, session, mail, queue, and filesystem configuration;
- rejects options, absolute paths, parent traversal, symlink escapes, missing files, and every test target outside the repository before starting PostgreSQL;
- runs only an explicit checked-in selected list, or caller-supplied existing test-file paths, with PHP `memory_limit=512M`;
- preserves a short synthetic PostgreSQL diagnostic tail on failure; and
- stops and deletes only a task root whose marker and live cluster identity match. An unproven cleanup target is refused.

Default invocation:

```bash
rtk scripts/run_logistics_phase2a_tests.sh
```

Focused invocation:

```bash
rtk scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php
```

## Reviewable change set B — Inventory return database failure atomicity

Files:

- `plugins/webkul/inventories/src/Services/TransferWorkflow.php`
- `plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php`
- `plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php`

The service now validates before persistence that the quantity map is non-empty, every key belongs to the source operation, and every quantity is a finite positive `int|float` under native UoM rounding. It then uses the source operation's database connection transaction for downstream reservation release, replicated operation/moves, native reserve, purchase association, the synchronous `OperationReturned` event, and cross-links. The event runs before Chatter message creation, so the tested late event failure cannot leave a deferred notification callback. Source moves and replicated return models retain the source connection. The executed proof covers this repository's configured single database connection; downstream services that use static model queries are not proven for alternate Eloquent connections.

The tests prove:

- empty, foreign-source, numeric-string, missing, zero, negative, and infinite values leave operations, moves, move lines, move links, chatter, quants, and source state unchanged;
- supported explicit partial and full returns preserve `origin_returned_move_id` and do not imply completed receipt;
- a late event failure on a two-step delivery restores the downstream move/reservation and every captured database effect;
- a valid retry after failed creation succeeds once;
- an outer caller rollback also removes all inner return effects; and
- the current API's omitted quantity map still produces its existing error, but no longer leaves an orphan header.

This is not successful-request idempotency, cumulative-return protection, or completion/cancellation concurrency safety. No new lock order or public request-key contract was introduced.

## Executed evidence

All database tests used a newly created task-owned PostgreSQL cluster; no existing database/volume or operational fixture was read.

| Command/scope | Result |
| --- | --- |
| Focused Inventory workflow + API via finalized runner after review fixes | 29 passed, 64 assertions, 125.58 seconds |
| Final Inventory workflow rerun after event/Chatter ordering fix | 12 passed, 34 assertions, 8.76 seconds |
| First 12-file selected run | 176 passed, 1 infrastructure failure; cluster encoding was `SQL_ASCII`, so Unicode notification JSON aborted one Sales API transaction |
| Sales API rerun after runner changed to UTF-8 | 17 passed, 63 assertions |
| Final fresh 12-file selected run | 177 passed, 515 assertions, 124.33 seconds |
| Focused Sales rollback/replay + two-process confirmation | 3 passed, 12 assertions, 12.41 seconds |
| `vendor/bin/pint --dirty --format agent` | passed after formatting Inventory files |
| `bash -n scripts/run_logistics_phase2a_tests.sh` | passed |
| `git diff --check` | passed after the final review edits |

The focused Sales concurrency test launches two independently bootstrapped PHP processes against committed shared synthetic fixtures and a deterministic file barrier. Both callers returned the same confirmed order while one procurement operation persisted. This proves PostgreSQL state-transition replay safety only. There is still no request-key/payload fingerprint contract.

Early runner attempts also exposed and corrected three infrastructure issues: macOS sandbox denial of PostgreSQL shared memory, an overlong Unix-socket path, and an unprivileged `data_directory` identity query. The final runner uses a short private path, validates server identity through the cluster superuser and application identity through the test role, and was executed outside the shared-memory sandbox only for the private cluster.

## Review evidence

A scoped multi-agent review covered correctness, project standards, testing, maintainability, reliability, and adversarial failure modes. The review excluded unrelated Sales changes, `AGENTS.md`, and the staged `docs/logistics/plan/**` tree.

Review findings applied and reverified:

- caller-supplied test paths are canonicalized, constrained to repository test PHP files, and reject absolute paths, parent traversal, symlink escapes, missing files, and non-test files before PostgreSQL starts;
- source moves are loaded through the operation relationship, and replicated return operation/move models retain the source connection name;
- the failure-injection event runs before Chatter message creation, preventing the tested late failure from scheduling a false follower notification;
- the new Pest closure has an explicit `: void` return type; and
- the backlog no longer claims an HTTP fake that the runner does not install.

Review findings recorded rather than silently expanded into this patch:

- the return API quantity/full-return contract and shared source-state validation require an explicit Inventory product/API decision;
- Chatter's application-termination notification scheduling remains a pre-existing shared boundary that is not generally after-commit aware; and
- alternate Eloquent connections, MySQL, cumulative/concurrent returns, and successful replay remain unproven.

The earlier simplification pass found no reusable duplicate abstraction. It produced three quality corrections, all applied, and three efficiency suggestions that were retained as non-blocking because they concerned pre-existing relation loading, deliberately strong test snapshots, or test-process listener isolation already covered by fresh application instances.

## Current gaps and release gates

- MySQL was not available/executed; it remains the required second-engine gate for shared behavior.
- No full repository, browser/E2E, load, or production-notification suite ran.
- Known Recon same-direction/atomicity/concurrency failures were not rerun or changed; they remain a money-slice gate.
- Inventory quant identity, 60+60/40+60 competition, completion-versus-cancel, cumulative concurrent returns, and successful-return replay remain item 5B.
- Return eligibility is still enforced by API/Filament callers rather than `TransferWorkflow::createReturn()` itself; shared source-state validation remains item 5B to avoid changing native workflow policy in this bounded repair.
- Chatter `Message::created` schedules follower notification in an application-termination callback rather than an after-commit callback. `OperationReturned` now runs before Chatter message creation, so the tested late event failure schedules no such callback. Suppression after a later cross-link or database-commit failure is not proven. Fixing the general shared Chatter notification boundary is a separate upstream-owned task; this report does not claim universal external-notification atomicity.
- The Inventory return API needs an explicit product contract for quantity input versus full return. Its current done-return path has no quantity map and returns HTTP 500; Phase 2A preserves that shape while preventing partial persistence.
- Accounts pre-bill advance accounting, supplier versus authorized recipient, recognition/tax timing, reversal, and payment-hold authority remain explicit blockers for the money slice.
- No install/uninstall/dependency-removal execution was performed.

## Actual diff review commands

Change set A:

```bash
rtk git diff -- config/testing.php \
  docs/logistics/TEST-BASELINE.md \
  docs/logistics/INTEGRATION-CONTRACTS.md \
  docs/logistics/PHASE-2-BACKLOG.md \
  docs/logistics/PHASE-2A-IMPLEMENTATION-REPORT.md \
  scripts/run_logistics_phase2a_tests.sh
```

Change set B:

```bash
rtk git diff -- \
  plugins/webkul/inventories/src/Services/TransferWorkflow.php \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php
```

The documentation files and runner are intent-to-add entries only; their contents remain unstaged, and ordinary `git diff` displays them. No commit or push was performed.

## Historical recommendation and stop point

This recommendation is retained to explain what followed this report. Phase 2B completed the referenced foundation work. Current sequencing is defined by [`plan/08-IMPLEMENTATION-ROADMAP.md`](plan/08-IMPLEMENTATION-ROADMAP.md).

After review and acceptance of the runner/lifecycle boundaries, the next smallest task is backlog item 8: the minimal non-posting `plugins/shafique/logistics` foundation. Keep Inventory item 5B and Accounts items 6-7 as separately owned prerequisites; do not expand the plugin-foundation task into stock or money posting.
