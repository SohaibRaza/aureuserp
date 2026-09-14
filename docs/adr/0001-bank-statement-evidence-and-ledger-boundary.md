# ADR 0001: Separate bank-statement evidence from posted ledger truth

- Status: Accepted
- Date: 2026-09-15
- Scope: Bank reconciliation

## Context

A bank statement is third-party evidence of bank activity. It is not the entity's books and may contain duplicates, bank errors, unidentified activity, or timing differences. Treating imported rows as journal entries would silently recognize transactions before their accounting nature and authorization are established.

## Decision

- Imported statement rows remain external evidence in the statement/import model.
- Book balances come only from posted move lines on the reconciliation's exact company-owned liquidity account through the statement-date cutoff.
- Import never creates a journal entry implicitly.
- A genuine book-side omission is recorded through a named accounting workflow such as an adjustment, transfer, returned payment, or correction.
- Every accepted statement line must receive exactly one supported terminal treatment before completion.

## Consequences

- Users may see bank rows without ledger candidates until the corresponding payment or entry is posted.
- The product must explain this state instead of manufacturing candidates.
- Import idempotency and immutable source evidence are accounting controls, not merely UX conveniences.

## Rejected alternatives

- Automatically posting every imported bank row: rejected because classification and counterpart accounts are not reliably known.
- Calculating book balance from matched rows: rejected because the posted GL is the authoritative book record.
