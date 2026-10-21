# Bank Reconciliation Architecture

**Status:** Current implementation map
**Updated:** 2026-10-21

## Boundaries

| Boundary | Responsibility |
|---|---|
| Accounts domain | Posted moves/lines, payment workflows, currency conversion, normal AR/AP reconciliation, reversals |
| Banking configuration | Company bank account, bank journal, currency, and liquidity-account identity |
| Statement ingestion | External evidence, parsing, staging, controls, quarantine, idempotency |
| Reconciliation domain | Candidate discovery, allocation matches, exceptions, adjusted-bank schedule, lifecycle, snapshots, audit |
| Filament surface | User intent, state display, validation feedback, and navigation; not the enforcement boundary |

## Service responsibilities

| Service | Responsibility |
|---|---|
| `ReconciliationSafety` | Global and per-company rollout availability |
| `ReconciliationMutationGuard` | Policy, active-company, and lifecycle enforcement |
| `StatementImporter` / `CsvStatementParser` | Normalize, validate, quarantine, accept, and deduplicate statement evidence |
| `FeedIngestionService` | Provider-neutral normalized ingestion contract |
| `MatchingEngine` / `ReconciliationRuleEngine` | Eligible candidate discovery and explainable proposals |
| `MatchService` | Locked exact allocations, accounting-mode effects, idempotency, confirmation, rejection, and unmatch |
| `AdjustmentService` | Posted bank-side accounting entries and controlled reversals |
| `ReconciliationExceptionService` / `StatementLineTreatment` | Timing items, bank errors, duplicates, corrections, and terminal-treatment exclusivity |
| `ReconciliationCalculator` | Posted GL balances, adjusted-bank schedule, difference, and blockers |
| `FinalizationService` | Completion preconditions and exception projection |
| `ReconciliationLifecycleService` | Review, completion, snapshots, reopen approval, and stale propagation |
| `BankOpeningBalanceService` | Authorized initial bank/equity entry and immutable provenance |

## Core records

The Accounts plugin owns the reconciliation schema and its permission migrations under `plugins/webkul/accounts/database/migrations`. Installing the core ERP does not create reconciliation tables. Installing Accounts first installs Products, then runs the Accounts schema and reconciliation migrations in timestamp order. The migrations moved from the application folder retain their original names, so existing development migration records do not run twice. The test bootstrap follows this same installation path instead of preloading Accounts tables.

- `recon_bank_reconciliations`: retained identity, dates, lifecycle, balances, completion/reopen/stale metadata.
- `recon_import_batches` and statement lines: source/control evidence and normalized bank activity.
- `recon_matches` and `recon_match_lines`: exact bank/ledger allocations, accounting mode, idempotency, and reversal provenance.
- `recon_adjustments`: link between a bank-line treatment and its posted move.
- `recon_reconciling_items`: timing differences, bank errors, duplicates, ownership, resolution, and carry-forward state.
- `reconciliation_snapshots`: versioned immutable completion payload and hash.
- `recon_events`: append-only business audit events.
- `reconciliation_rollouts`: company enablement, attestations, disable history.
- `recon_bank_opening_balances`: one-time company-bank opening-balance provenance linked to the posted move.

Snapshot hashes cover the JSON serialization captured at completion. PostgreSQL stores the payload as `json`; MySQL stores it as JSON-validated text because native MySQL JSON changes object-key order. Neither engine may update or delete a saved snapshot. A forward migration converts an existing empty MySQL snapshot table. If it contains history, the migration stops for evidence review: changing the column cannot recover the original serialization, and saved hashes must not be rewritten to hide a mismatch.

## Write sequence

All material writes follow this order:

1. Check rollout and authorization.
2. Start a database transaction.
3. Reload and lock the reconciliation and relevant sources.
4. Revalidate state, ownership, currency, dates, residuals, and idempotency against locked data.
5. Invoke the Accounts workflow for any actual journal posting or reversal.
6. Write reconciliation evidence and source treatment atomically.
7. Append the business event.
8. Commit; any failure rolls back the complete operation.

## Read and reporting sequence

- Live preparation screens calculate from current posted GL and current evidence without mutating persisted balances during a read.
- Completion recalculates under lock and persists the final calculation in an immutable snapshot.
- Completed reports read the snapshot. They do not recalculate historical conclusions from mutable current rows.

## Concurrency model

- Source rows and the reconciliation are locked before remaining amounts are accepted.
- Accounts posting, cancellation, reset, reversal and line reconciliation acquire a shared PostgreSQL transaction advisory lock for their company before checking completed bank periods. On MySQL, the same operations acquire a shared lock on the existing Company row; completion acquires an exclusive lock on that row. On either engine, completion acquires the exclusive lock before calculating balances and writing its snapshot. The lock exists independently of reconciliation rows, including when a company's first reconciliation is being completed.
- These locks use immediate acquisition attempts. A competing completion or accounting change causes a validation message asking the user to refresh and retry; it does not wait while holding other row locks. Unrelated accounting changes can hold shared locks together. Locks remain held until the enclosing transaction ends, and these operations require READ COMMITTED isolation on both engines so checks after acquisition read committed changes.
- The company lock deliberately covers more than a single bank account. This keeps the protocol small and avoids acquiring several account locks in inconsistent order. Completion can briefly reject an otherwise unrelated posting in the same company; the user can retry after completion ends.
- Command fingerprints/idempotency keys distinguish a safe retry from a conflicting instruction.
- Unique constraints prevent duplicate ownership/consumption where applicable.
- Genuine contention proof uses separate database connections on each supported engine and a disposable test database; ordinary transaction-wrapped tests cannot prove lock scheduling.

## Database protection

Application model hooks provide early feedback, while database constraints and triggers on PostgreSQL and MySQL protect immutable snapshots, events, and opening-balance evidence against direct application-role update/delete paths. MySQL history migrations refuse rollback before executing any DDL, even when empty, because DDL implicitly commits. Recovery uses a reviewed forward migration. PostgreSQL rollback guards refuse to drop populated history. Migration tests must use disposable databases.

Payment request keys are case-sensitive. PostgreSQL serializes keyed payment registration with a transaction advisory lock. MySQL uses a persistent request-hash row and an InnoDB upsert to retain an exclusive row lock until the enclosing transaction ends; the row does not itself represent a completed payment. Payment results and accounting effects still commit or roll back together.

MySQL application sessions explicitly use UTC, matching the application timezone. This preserves the instant represented by offset-bearing timestamps when MySQL returns TIMESTAMP values without an offset. The private runner verifies the session setting before testing. Existing MySQL timestamps from an incorrectly configured installation require inspection; changing the session setting does not automatically repair historical values.
