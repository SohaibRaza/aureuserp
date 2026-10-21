# Bank Reconciliation Release Evidence Register

**Updated:** 2026-10-21
**Rule:** A claim is `Verified` only when its evidence is retained or independently repeatable. “Implemented” is not synonymous with “verified.”

## Evidence status

| Gate | Current evidence | Status |
|---|---|---|
| Accounting calculation and controls after upstream merge | `ReconciliationCalculationTest.php`: 5 tests / 43 assertions; `ReconciliationControlsTest.php`: 11 tests / 66 assertions | Verified after isolating test liquidity accounts |
| Focused opening balance, workspace, and matching | 23 tests / 134 assertions in the last recorded focused run | Verified in recorded development run |
| Safety and schema | 12 tests / 35 assertions in the last recorded focused run | Verified in recorded development run |
| Full reconciliation suite | Plan records 127 tests / 636 assertions before later merge/fixes | Historical pass; rerun required for current commit |
| Selected Accounts regressions | Plan records 40 tests / 96 assertions before later merge/fixes | Historical pass; rerun required for current commit |
| Real browser accountant journey | User completed `REC-BROWSER-202609`, verified four terminal treatments, posted fee/interest, opening entry, zero difference, completion, report, snapshot, audit timeline, and locked UI | Verified manually for that scenario |
| Immediate lifecycle UI refresh | User verified Start review/Back to in progress behavior after correction | Verified manually |
| Keyboard and narrow-screen behavior | Required by U18; no durable artifact referenced here | Not evidenced in this register |
| True multi-connection contention | Plan records a prior 1 test / 13 assertion PostgreSQL proof | Historical pass; rerun required after final merge if used as release evidence |
| Expanded migration/rollback rehearsal | Plan explicitly records that the expanded rehearsal was not rerun after adding root migrations | Open |
| Local migrations | User reported pending reconciliation migrations applied | Development confirmation; verify migration ledger before release |
| Independent hand-worked accounting schedule | Plan records 4 tests / 46 assertions and remediation of a bank-error sign defect | Historical pass; retain/rerun for release package |

## Required release evidence

Before declaring the module release-ready, retain:

1. Commit SHA, PHP/PostgreSQL versions, database target, and configuration gates.
2. Clean focused and full reconciliation test results.
3. Selected Accounts payment/move/reconciliation regression results.
4. Disposable migration-up/rollback/reapply rehearsal output.
5. Separate-connection concurrency result.
6. Browser journey evidence at desktop and narrow width, including keyboard-only operation and visible validation/blockers.
7. Independent accountant sign-off on the hand-worked schedule and completed report.
8. Backup/restore evidence, controller sign-off, and company rollout record for a release environment.

The commands and exact expected counts may change as tests evolve. The release decision must use current results, not this document's historical counts.

## 2026-10-21 Accounts reconciliation and completed-period safeguards

This development verification covers the current working tree on the `production` branch at base commit `8ce68a6a1c27468e0a0ce04d16734aeae3f7c9d5`; it does not identify a deployment environment. No application database changes, commits or pushes were performed.

Changes reviewed before the combined run:

- A reopen request keeps the completed bank period closed until approval. Posting, reset and unreconciliation remain blocked while approval is pending.
- Accounting changes hold a shared transaction advisory lock for the company. Completion holds the exclusive counterpart before recalculation and snapshot creation. Immediate acquisition attempts return a retry message instead of introducing a row-lock wait cycle. Both operations require READ COMMITTED isolation.
- Payment reconciliation refreshes update only state and matching flags. A later editor does not replace the creator, and a posted payment retains its stored destination account even when the partner default changes or the caller has an old draft journal loaded.
- The Invoices summary authorizes all four document types through their owning Invoices policies. Accounts-only permission does not authorize its reconciliation callbacks.

The first two payment regressions reproduced the creator overwrite before the fixes. The first three-file check then passed 18 tests and failed eight test cases because of fixture and test setup errors: a missing import, chaining a second Filament action after the first had halted, missing terminal-treatment actor and timestamp, and a secondary connection configuration that replaced the primary connection. Those setups were corrected without weakening assertions or changing the business rules. The subsequent focused run passed **26 tests and 161 assertions in 19.47 seconds**, with no skipped tests.

The focused files are `PaymentReconciliationIdentityTest.php`, `InvoiceSummaryTest.php` and `AccountingPeriodBoundaryTest.php`. The period tests use distinct PostgreSQL backends, cover both conflicting lock directions, verify that actual completion retains the exclusive lock through its enclosing transaction, and verify release on rollback. They do not claim a load benchmark or a general fiscal-year closing system.

Focused verification used **PostgreSQL 18.1 and PHP 8.4.21** in private task-owned socket-only clusters. The successful focused database was `aureuserp_20261021122819_13661_testing`; the runner removes these disposable databases and clusters after completion. The focused log is `/private/tmp/aureus-accounts-focused-final-20261021.log`. Independent source review found the stale preloaded payment-journal case; it was fixed and covered before the combined run. No further concrete defects were reported in the reviewed changes.

After review, one combined **14-file selection passed 168 tests and 928 assertions in 86.17 seconds**, with no failed or skipped tests. It included the three focused files plus `ReconciliationIntegrityTest.php`, `MoveLifecycleTest.php`, `PaymentAtomicityTest.php`, `PaymentConcurrencyTest.php`, `PaymentCorrectionTest.php`, `LifecycleTest.php`, `ReconciliationControlsTest.php`, `MatchAccountingIntegrationTest.php`, `BankOpeningBalanceServiceTest.php`, `AdjustmentServiceTest.php` and `CompanyCurrencyGuardTest.php`. Payment contention tests were enabled. The private database was `aureuserp_20261021122913_16025_testing`; the retained local log is `/private/tmp/aureus-accounts-combined-20261021.log`.

Scoped PHP 8.4 Pint and staged/unstaged Git whitespace checks passed. The original index and base commit were preserved. This selection establishes the listed development regressions, including real separate-connection period-lock checks; it is not a full ERP or full reconciliation-suite release baseline. Browser, migration-rehearsal and independent-accountant evidence requirements listed above remain separate.
