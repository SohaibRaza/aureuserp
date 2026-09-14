# ADR 0005: Enforce financial mutations through layered controls

- Status: Accepted
- Date: 2026-09-15
- Scope: Authorization, rollout, and concurrency

## Context

Hiding a button does not protect accounting data. Financial mutations must remain safe when called from Livewire, commands, tests, or future integrations, including under concurrent requests.

## Decision

Every financial mutation is protected by the applicable layers:

1. Global configuration gate.
2. Optional company rollout gate with backup, staging verification, and controller sign-off.
3. Policy permission and active-company ownership.
4. Lifecycle-state validation.
5. Company, journal, account, currency, source, and residual validation.
6. Database transaction and row locks for competing operations.
7. Database constraints or triggers for immutable evidence and uniqueness.
8. Append-only event evidence for material actions.

Creating a draft is permitted when the company has a valid bank journal, while financial actions remain gated. Permissions are separated into view, prepare, review, complete, reopen, and opening-balance posting.

## Consequences

- UI permissions improve usability but are never the sole control.
- Disabling rollout stops future mutations without deleting evidence.
- Concurrency claims require tests using truly separate PostgreSQL connections.
