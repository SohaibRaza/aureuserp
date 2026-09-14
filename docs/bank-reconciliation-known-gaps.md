# Bank Reconciliation Known Gaps and Non-Claims

## Product gaps

1. **Progressive workspace:** The implemented single workspace is functionally dense. The intended redesign is Overview -> Import -> Match -> Exceptions -> Adjustments -> Review -> Complete, with direct navigation retained for experienced users.
2. **Bank-specific import profiles:** There are no certified versioned adapters for OFX/QFX, MT940, CAMT.053, or individual bank CSV formats. Unknown layouts must be mapped/validated rather than guessed.
3. **Provider feeds:** The normalized ingestion boundary exists, but no speculative provider is considered supported without adapter contracts, operational monitoring, and representative fixtures.
4. **Broader treasury functions:** Reconciliation is not cash positioning, liquidity forecasting, bank confirmation, payment fraud detection, or automated bank connectivity.

## Control limitations

- A balanced reconciliation can still contain offsetting errors, collusion, or fabricated source documents.
- The application cannot independently prove that an uploaded CSV came from the bank.
- Maker-checker effectiveness depends on correct role assignments and enabling different-reviewer configuration where required.
- Rollout flags record attestations; they do not perform or prove the backup, staging review, or controller approval themselves.
- Database immutability assumes the application role cannot bypass PostgreSQL protections as a database superuser or migration owner.

## Open release evidence

The current evidence register still requires a clean post-merge broad-suite run, expanded disposable migration/rollback rehearsal, current concurrency rerun, and retained keyboard/narrow-screen browser evidence before an unqualified release-ready claim.

## Migration consolidation decision

Because the project is greenfield and has no production data, consolidation may reduce migration complexity before the first release. It should occur only as a separately reviewed task after the final schema stabilizes. The consolidated baseline must preserve all constraints, indexes, triggers, permissions, seeds, data backfills needed for a fresh install, and a clean rollback/reapply rehearsal. Never edit migration history after any environment is treated as production or shared immutable release history.
