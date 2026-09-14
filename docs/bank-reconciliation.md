# Bank Reconciliation Documentation

This is the entry point for the enterprise bank-reconciliation capability.

## Canonical documents

1. [Accounting specification](bank-reconciliation-accounting-specification.md) — equations, recognition boundaries, treatments, completion rules, and non-claims.
2. [Architecture](bank-reconciliation-architecture.md) — domain boundaries, services, records, transaction ordering, reporting, and concurrency.
3. [Workflow and controls](bank-reconciliation-workflow-and-controls.md) — lifecycle, end-to-end operating flow, permissions, and mutation invariants.
4. [Import contract](bank-reconciliation-import-contract.md) — normalized source requirements, controls, quarantine, idempotency, and deferred formats.
5. [Operations runbook](bank-reconciliation-operations-runbook.md) — enablement, safe disable, close procedure, corrections, and verification policy.
6. [Coverage matrix](testing/bank-reconciliation-coverage-matrix.md) — risk and boundary test contract.
7. [Release evidence register](testing/bank-reconciliation-release-evidence.md) — verified, historical, and still-open evidence.
8. [Known gaps](bank-reconciliation-known-gaps.md) — product limitations, control non-claims, open gates, and migration-consolidation criteria.
9. [Architecture Decision Records](adr/README.md) — durable rationale and rejected alternatives.
10. [Implementation plan](plans/2026-09-07-enterprise-bank-reconciliation-plan.md) — requirements, U1-U18 history, sequencing, and corrective audit.

## Document authority

When documents conflict, use this order:

1. Accounting specification and accepted ADRs.
2. Workflow/control and import contracts.
3. Current implementation architecture.
4. Implementation plan and coverage/evidence records.
5. Historical design document.

The [original design](bank-reconciliation-design.md) is retained for traceability but is explicitly historical because important accounting boundaries changed during independent U13-U18 audits.

Application behavior remains subject to tests and independent accounting review. Documentation does not make an unverified release gate pass.
