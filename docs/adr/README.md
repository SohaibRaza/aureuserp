# Architecture Decision Records

ADRs document consequential decisions that should remain understandable after the implementation context is gone.

Status values are `Proposed`, `Accepted`, `Superseded`, or `Deprecated`. An accepted ADR is changed only to clarify wording; a changed decision receives a new ADR that supersedes the old one.

## Bank reconciliation decisions

| ADR | Decision | Status |
|---|---|---|
| [0001](0001-bank-statement-evidence-and-ledger-boundary.md) | Separate bank evidence from posted ledger truth | Accepted |
| [0002](0002-company-bank-account-and-liquidity-identity.md) | Use first-class company bank-account and liquidity identity | Accepted |
| [0003](0003-allocation-matching-without-invented-accounting-reconciliation.md) | Keep bank clearing allocations distinct from AR/AP reconciliation | Accepted |
| [0004](0004-immutable-completion-and-controlled-reopen.md) | Finalize to immutable evidence and reopen through approval | Accepted |
| [0005](0005-layered-financial-mutation-controls.md) | Enforce financial mutations through layered controls | Accepted |

## Repository workflow decisions

| ADR | Decision | Status |
|---|---|---|
| [0006](0006-issue-and-project-tracking.md) | Keep repository labels generic and track initiative phases in module Projects | Accepted |

## Project platform decisions

| ADR | Decision | Status |
|---|---|---|
| [0007](0007-postgresql-project-baseline.md) | Use PostgreSQL as the project database and acceptance baseline | Superseded by 0008 |
| [0008](0008-postgresql-primary-and-mysql-support.md) | Keep PostgreSQL primary and support MySQL with equivalent integrity controls | Accepted; MySQL compatibility verification pending |

## Logistics domain decisions

| Decision record | Decision | Status |
|---|---|---|
| [Q3 stock-balance identity](../logistics/plan/12-OPEN-DECISIONS.md#q3-approved-stock-balance-identity) | Inventory and Sales approved one stock-balance row per company, product, location, lot, package and stock owner, with equal empty optional values treated as the same identity. The technical migration gate and remaining Q3 rules remain open. | Accepted identity; Q3 partially approved |
| [Quantity, loading and first-journey rules](../logistics/plan/12-OPEN-DECISIONS.md#quantity-loading-and-first-journey-decision-proposal--2026-10-15) | The project owner approved parts A-D on 2026-10-15: quantity and historical pack meaning, remaining demand, loading checks, normal role separation, and dedicated-vehicle and multi-stop responsibilities. Actual configuration, other Q decisions and live acceptance remain separate. | Accepted for the stated scope |
| [SF1 and SF2 legal Company mapping](../logistics/plan/12-OPEN-DECISIONS.md#facts-and-configuration-still-required) | The owner confirmed on 2026-10-15 that the two factories are separate units and locations of one legal Company. Use separate warehouse or loading-site records. Transfers between factory stocks require native dispatch and receipt; direct collection from both factories records a separate issue at each origin. | Accepted business mapping; native record IDs still require configuration |
| [Evidence and suspension controls](../logistics/plan/12-OPEN-DECISIONS.md#evidence-and-suspension-controls-approved--2026-10-15) | The owner approved private PDF/PNG/JPEG evidence with Company and permission checks, mandatory configured retention and legal holds, and no default identity documents or audio. Suspension blocks new commitments and releases, including unused earlier approvals, while authorized incident recording and recovery of issued goods remain available. Resume requires fresh checks. | Accepted controls; retention periods and other operating limits remain configuration or open decisions |
