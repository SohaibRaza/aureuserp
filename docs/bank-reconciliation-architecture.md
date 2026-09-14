# Bank Reconciliation Architecture

**Status:** Current implementation map
**Updated:** 2026-09-15

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

- `recon_bank_reconciliations`: retained identity, dates, lifecycle, balances, completion/reopen/stale metadata.
- `recon_import_batches` and statement lines: source/control evidence and normalized bank activity.
- `recon_matches` and `recon_match_lines`: exact bank/ledger allocations, accounting mode, idempotency, and reversal provenance.
- `recon_adjustments`: link between a bank-line treatment and its posted move.
- `recon_reconciling_items`: timing differences, bank errors, duplicates, ownership, resolution, and carry-forward state.
- `reconciliation_snapshots`: versioned immutable completion payload and hash.
- `recon_events`: append-only business audit events.
- `reconciliation_rollouts`: company enablement, attestations, disable history.
- `recon_bank_opening_balances`: one-time company-bank opening-balance provenance linked to the posted move.

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
- Command fingerprints/idempotency keys distinguish a safe retry from a conflicting instruction.
- Unique constraints prevent duplicate ownership/consumption where applicable.
- Genuine contention proof uses separate PostgreSQL connections and a disposable test database; ordinary transaction-wrapped tests cannot prove lock scheduling.

## Database protection

Application model hooks provide early feedback, while PostgreSQL constraints and triggers protect immutable snapshots, events, and opening-balance evidence against direct application-role update/delete paths. Migrations are part of the control design and must be rehearsed with rollback on a disposable database.
