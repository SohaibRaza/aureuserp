# ADR 0004: Finalize to immutable evidence and reopen through approval

- Status: Accepted
- Date: 2026-09-15
- Scope: Reconciliation lifecycle and audit

## Context

A completed reconciliation is evidence supporting a period-end assertion. Editing it in place would destroy reproducibility and could leave later periods based on invalid opening assumptions.

## Decision

- Completion is allowed only from `review`, under a row lock and database transaction.
- Completion requires valid statement arithmetic, zero reconciliation difference, terminal treatment for every accepted statement line, valid treatment references, no unresolved proposals, and no unsupported reconciling items.
- Completion writes a versioned, hashed snapshot and append-only event, marks the bank statement complete, and locks the workspace.
- Completed evidence cannot be directly edited or deleted. Corrections use reversing entries and a controlled reopen request/approval with reasons.
- When configured, requester and approver must be different users.
- Reopening a period marks later reconciliations with the same bank/liquidity identity stale. Stale periods require controlled rework and cannot silently remain authoritative.

## Consequences

- Historical snapshots remain available after reopening.
- Reports for completed periods are rendered from their snapshot rather than mutable live rows.
- Reopen impact must be previewed and audited.
