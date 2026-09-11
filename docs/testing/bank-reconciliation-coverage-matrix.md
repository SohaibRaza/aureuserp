# Bank Reconciliation Coverage Matrix

## Purpose and completion rule

This matrix is the executable verification contract for U1-U17. It treats the accounting invariants and failure modes as the source of truth; the implementation is not evidence of its own correctness.

"Every possible scenario" cannot be proven for arbitrary files, dates, currencies, database failures, or concurrent schedules. The release standard is therefore **risk-complete and boundary-complete coverage**: every supported workflow, lifecycle state, permission boundary, ownership boundary, terminal treatment, monetary boundary, reversal path, and known failure class must have a deterministic test. Property/fuzz, browser, device, and operational release proof belongs to U18.

Tests must run against the isolated `aureuserp_testing` PostgreSQL database. They must never target production or a developer's normal application database.

## Unit coverage

| Unit | Required evidence | Primary automated coverage | Remaining release proof |
|---|---|---|---|
| U1 Safety and characterization | Financial mutations off by default; rollout/company gates; legacy state is inventoried without mutation | `ReconciliationSafetyTest.php`, `LegacyReconciliationAuditTest.php` | Operational command walkthrough in U18 |
| U2 Schema and ownership | Company/account/currency FKs, checks, uniqueness, immutable evidence, cross-company rejection | `ReconciliationSchemaTest.php`, `CompanyBankAccountTest.php` | Migration rollback rehearsal in U18 |
| U3 Staged ingestion | Valid import, atomic rollback, row/file idempotency, source identity, dates, decimals, malformed input | `StatementImportTest.php`, `CsvStatementParserTest.php` | Property-based parser corpus in U18 |
| U4 Calculation | Opening/closing GL cutoffs, adjusted-bank schedule, signs, currency precision, invalid sources | `ReconciliationCalculationTest.php`, `AccountantGoldenReconciliationTest.php`, foreign-currency adjustment coverage | Hand-worked clean/nonzero, timing, fee, interest, return, transfer, FX, duplicate, bank-error debit/credit, partial/split, and transaction-currency GL schedules pass; browser-visible proof remains |
| U5 Matching | 1:1, 1:N, N:1, split/partial, residuals, direction, tolerance, provenance, idempotency, reversal | `MatchingEngineTest.php`, `MatchAccountingIntegrationTest.php`, opt-in `MatchConcurrencyTest.php` | True two-process PostgreSQL race passes separately; test intentionally commits immutable audit evidence |
| U6 Adjustments/exceptions | Balanced posted entry, account validation, fee/interest/direct charge, reverse, no-GL bank errors/duplicates | `MatchAccountingIntegrationTest.php`, `ReconciliationExceptionTest.php` | Dedicated UI paths and accounts regressions |
| U7 Lifecycle/audit | State transitions, maker-checker, permissions, snapshots, historical stale propagation, append-only evidence | `ReconciliationControlsTest.php`, `ReconciliationSchemaTest.php` | Browser-visible permissions and DB-role rehearsal |
| U8 Workspace/report | Routes, selection behavior, mutation authorization, locked states, immutable report source | `ReconciliationWorkspacePageTest.php` | Livewire/browser workflow in U18 |
| U9 Rules/feed contract | Priority, determinism, explainability, rule scope, staged feed contract | `U9RuleAndFeedContractTest.php` | Provider adapter contract tests when adapters exist |
| U10 Controlled rollout | Readiness failures, successful enablement, signoff/backup/staging gates, reversible disable | `ReconciliationReadinessTest.php`, focused U10 boundary tests | Operator rollback exercise in U18 |
| U11 Company bank account | Company-owned account creation, journal linkage, active/currency/account validity, partner separation | `CompanyBankAccountTest.php` | Manual configuration walkthrough |
| U12 Terminal treatment | Exactly one treatment per accepted line; fees, interest, returned payment, transfer, FX, duplicate, bank error, blocker | `ReconciliationExceptionTest.php`, focused terminal-treatment tests | Full UI workflow in U17/U18 |
| U13 Accounting integration | No corruption of GL, AR/AP, payments, partial/full reconcile; exact allocation/unmatch restoration | `MatchAccountingIntegrationTest.php`, `ReconciliationSafetyTest.php`, schema assertions | Selected accounts regression suite |
| U14 Mutation/concurrency | Every public mutation rechecks gate, permission, company, ownership, lifecycle and stale inputs under locks | `ReconciliationControlsTest.php`, focused U14 atomicity tests | True multi-connection contention in U18 |
| U15 Period/source integrity | Period preserved unless explicitly adopted; preview, controls, quarantine, account/currency identity, idempotency | `StatementImportTest.php`, `CsvStatementParserTest.php` | Bank-profile corpus after profile feature is built |
| U16 Reopen/stale/audit | Request before approval, optional identity separation, impact preview, chronological staling, reproducible snapshot, SQL immutability | `ReconciliationControlsTest.php`, `ReconciliationSchemaTest.php`, focused U16 mutation tests | Manual maker-checker walkthrough |
| U17 Professional surface | Visible match/reject/unmatch, selected totals, dedicated treatments, source drill-down, correction/resolution, locks, report retrieval | `ReconciliationWorkspacePageTest.php` plus focused page/action tests | Narrow-screen, keyboard, and complete browser journey remain open in U18 pending the required browser-visibility choice |

## Cross-cutting scenario matrix

Every financial mutation is tested in all applicable dimensions below, either directly or through a shared mutation-guard contract test:

- lifecycle: `draft`, `in_progress`, `review`, `completed`, `reopen_requested`, `stale`;
- authorization: unauthenticated, no permission, preparer, reviewer, prohibited self-approval, valid separate approver;
- ownership: correct company/account/journal/currency, foreign company, foreign journal, foreign statement, foreign move line;
- money: zero, positive, negative, sub-cent/rounding boundary, exact residual, over-allocation, under-allocation, mixed direction, foreign-currency residual;
- source state: missing, rejected/quarantined, accepted, already treated, partially consumed, fully consumed, reversed, stale selection;
- repetition: same request, same key/same payload, same key/different payload, repeated file, repeated row, repeated approval/reversal;
- atomicity: validation failure before writes, failure after intermediate work, concurrent confirmation, and rollback with no orphan rows;
- evidence: actor, reason, source reference, immutable event, immutable snapshot, exact reversal provenance.

## Explicit exclusions and non-claims

- OFX/QFX, MT940, CAMT.053, and bank-specific versioned CSV profiles are a recorded U15 future feature. Tests cannot claim support until those adapters and representative bank fixtures exist.
- Passing PHP/service/page tests does not prove browser accessibility, responsive layout, database-role deployment, backup recovery, or production readiness. Those are U18 release gates.
- SQLite is not an acceptable substitute for the PostgreSQL constraint, trigger, locking, and concurrency tests.

## Required execution order

1. Run newly changed focused tests in reusable isolated-database mode.
2. Run the complete `tests/Feature/Recon` suite.
3. Run selected Accounts workflow regressions.
4. Run U18 browser, concurrency, migration/rollback, and manual accountant walkthroughs.
5. Record exact commands, database target, pass/fail counts, and unresolved exclusions before release approval.
