# Bank Reconciliation Release Evidence Register

**Updated:** 2026-09-15
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
