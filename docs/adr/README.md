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
