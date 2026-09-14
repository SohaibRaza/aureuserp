# Bank Reconciliation Workflow and Controls

## Lifecycle

| Status | Meaning | Permitted direction |
|---|---|---|
| `draft` | Identity established; evidence may not yet be imported | `in_progress` or controlled cancellation |
| `in_progress` | Preparation, importing, matching, and treatments | `review` |
| `review` | Evidence frozen for review | `completed` or `in_progress` |
| `completed` | Final snapshot is authoritative and workspace is locked | `reopen_requested` |
| `reopen_requested` | Correction request awaits approval | `reopened` after approval |
| `reopened` | Historical period is editable under correction controls | `review` after rework |
| `stale` | Conclusion invalidated by prior-period change | controlled stale rework |
| `cancelled` | Abandoned session retained for audit | terminal/read-only |
| `legacy_unverified` | Legacy evidence is not trusted under current controls | read-only until repaired |

## End-to-end control flow

1. **Configure banking:** create an active company bank account and link it to a bank journal with a company-owned liquidity account and compatible currency.
2. **Establish opening books:** when required for a new company, post the opening bank balance against an approved equity account through the dedicated service. Never type a plug into reconciliation.
3. **Create draft:** select the valid bank journal and period. The identity is retained on the reconciliation.
4. **Import and verify:** preview mapping, dates, account/currency identity, opening/closing balances, transaction count, debit/credit totals, duplicates, and quarantined errors before acceptance.
5. **Compare:** display accepted bank rows and posted eligible liquidity lines through the cutoff.
6. **Match:** use explainable automatic proposals or exact manual allocations. Ambiguous proposals require review.
7. **Treat exceptions:** post genuine book-side transactions; carry forward GL-backed timing items; approve supported bank errors; exclude proven duplicates.
8. **Review:** freeze preparation changes, inspect control totals, matches, adjustments, exceptions, outstanding items, audit events, and difference.
9. **Complete:** atomically validate all blockers, persist snapshot/hash, mark the statement complete, and lock the session.
10. **Correct:** request and approve reopen with reasons; stale affected later periods and reperform them chronologically.

## Permission matrix

| Permission | Purpose |
|---|---|
| `reconciliation.view` | View list, workspace, reports, evidence, and audit history |
| `reconciliation.prepare` | Create and prepare, import, match, unmatch, and treat supported items |
| `reconciliation.review` | Start or return review according to policy |
| `reconciliation.complete` | Approve and complete a balanced reconciliation |
| `reconciliation.reopen` | Request/approve historical reopening and stale rework |
| `reconciliation.opening_balance.post` | Post the one-time supported opening balance |

The policy must also enforce company ownership. UI visibility is not authorization. When `RECONCILIATION_REQUIRE_DIFFERENT_REVIEWER=true`, the preparer cannot complete their own submission and a reopen requester cannot approve their own request.

## Mutation invariant

Every public financial mutation must recheck, inside the service path:

- global and company rollout gates;
- authenticated policy ability;
- active company;
- lifecycle status;
- company/journal/bank/liquidity/currency ownership;
- source state and remaining amount;
- idempotency or uniqueness; and
- locked current database state inside a transaction.

Failure must leave no partial accounting or reconciliation evidence and must produce a user-visible explanation in the UI.
