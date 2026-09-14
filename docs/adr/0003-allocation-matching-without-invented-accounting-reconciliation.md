# ADR 0003: Keep bank clearing allocations distinct from AR/AP reconciliation

- Status: Accepted
- Date: 2026-09-15
- Scope: Match accounting effects

## Context

Bank reconciliation proves that a bank-statement amount agrees with posted activity in the bank liquidity account. It is not automatically the same event as reconciling receivable and payable debit/credit lines. Creating partial reconciles for same-direction bank clearing lines can corrupt residuals or invent an AR/AP settlement relationship.

## Decision

- Ledger candidates are posted, in-scope liquidity move lines through the cutoff.
- A match records exact signed allocations and residual provenance across one-to-one, split, and grouped matches.
- The default bank-clearing mode is `allocation_only`; it does not create `accounts_partial_reconciles` or `accounts_full_reconciles`.
- Accounting-owned partial/full reconciliation is used only where the selected debit/credit lines form a valid accounting reconciliation under the Accounts domain's rules.
- The UI, report, snapshot, reversal logic, and audit trail disclose the accounting mode.
- Unmatching restores only effects owned by that match and verifies that source ledger state has not changed unexpectedly.

## Consequences

- A bank match can be valid while AR/AP settlement remains a separate accounting workflow.
- Match confirmation must be transactional, idempotent, and protected against concurrent reuse of either source.
