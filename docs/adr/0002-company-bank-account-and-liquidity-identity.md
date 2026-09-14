# ADR 0002: Use first-class company bank-account and liquidity identity

- Status: Accepted
- Date: 2026-09-15
- Scope: Banking configuration and reconciliation ownership

## Context

Company bank accounts were previously discoverable through generic partner/contact concepts. That is confusing for accountants and insufficient for company, currency, and ledger isolation.

## Decision

A reconciliation is bound to an immutable identity tuple:

`company + bank journal + company bank account + liquidity GL account + currency`

The journal is eligible only when it is a bank journal with:

- an active company-owned bank account;
- compatible journal/bank-account currency; and
- a dedicated default liquidity account owned by the company.

Cash journals do not enter the bank-reconciliation flow. Company bank accounts are configured under Accounting and linked directly from the journal UI; generic Contacts are not the setup boundary.

## Consequences

- Candidate selection, imports, matching, adjustments, reporting, and historical staling all use the same identity.
- Cross-company or cross-currency references are rejected in services and constraints.
- Changing a journal later must not rewrite the identity retained by an existing reconciliation.
