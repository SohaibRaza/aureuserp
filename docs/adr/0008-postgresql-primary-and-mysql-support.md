# ADR 0008: PostgreSQL is primary and MySQL is also supported

- **Status:** Accepted; outstanding MySQL implementation and verification work remains.
- **Date:** 2026-10-21
- **Decision owner:** AureusERP project owner
- **Supersedes:** [ADR 0007](0007-postgresql-project-baseline.md)

## Context

The project owner explicitly changed the database support decision: PostgreSQL remains the primary database, and MySQL must also be supported. This supersedes the PostgreSQL-only restriction; it does not turn previous PostgreSQL test results into MySQL evidence. The project remains development-only, with no deployment configured.

## Decision

1. PostgreSQL is the primary local development and acceptance baseline. MySQL is an additional supported target for the ERP and its extensions, including Inventory, Accounts and Logistics. MariaDB is not included.
2. Retain the existing PostgreSQL 16 and MySQL 8.0 CI lanes as the minimum verification versions. Local PostgreSQL 18.1 evidence is recorded separately. Record the exact engine and version for each run; a configured CI lane is not proof that it passes.
3. Changes affecting shared database behavior must preserve the same business rules on both engines. Migrations, null-aware stock identity, atomic stock updates, payment replay, completed-period locking and immutable history require equivalent guarantees. Use engine-specific implementations where required, without bypassing controls on MySQL.
4. Verify affected fresh installations, migrations, business regressions and independent-session concurrency on both engines before declaring those paths compatible. Inspect existing data before schema changes. Migration rollback must preserve historical records or refuse unsafe rollback on either engine.
5. PostgreSQL development can continue while bounded MySQL portability tasks are open. Report those gaps explicitly; do not claim MySQL release readiness until the affected migration, integrity and workflow checks pass. Keep compatibility failures visible rather than removing the MySQL lane or weakening assertions.
6. An operational MySQL target needs its own installation, configuration, backup, recovery and migration verification before use. No application database is converted or reset by this decision.

## Known implementation gaps at acceptance

The current implementation includes PostgreSQL advisory transaction locks in Accounts, a PostgreSQL NULLS NOT DISTINCT stock-identity constraint, PL/pgSQL immutable-history triggers, and PostgreSQL table locks in rollback guards. These need reviewed MySQL equivalents and tests. This documentation change does not implement them or establish that the MySQL CI lanes pass.

## Compatibility implementation — 2026-10-21

The implementation now provides MySQL functional stock-identity indexes, transactional InnoDB payment-request locks, Company-row period locks under READ COMMITTED, explicit UTC MySQL sessions preserving approval timestamps, and database triggers protecting immutable history. MySQL refuses deletion of a Company or stock owner referenced by a stock balance, preserving the identity and avoiding a reproduced MySQL 8.0.46 crash during cascading SET NULL collision rollback. PostgreSQL retains its existing foreign-key behavior, null-aware constraint and transaction advisory locks. MySQL bank source IDs and payment keys use case-sensitive comparison. MySQL history rollback refuses before DDL, including empty schemas; use reviewed forward recovery. A forward migration installs missing history triggers on existing PostgreSQL Logistics tables.

These statements describe code, not release certification. Current test results and exact engine versions belong in the [verification register](../testing/bank-reconciliation-release-evidence.md). The supported CI minimum versions remain separate from local evidence. Partially failed MySQL installations need an inspected schema and migration-ledger recovery; changing old migration files does not repair them automatically.

## Consequences

- The normal test and CI policy now includes both engines; the historical PostgreSQL-only instruction is no longer current.
- Keep the private PostgreSQL runner for primary development verification. MySQL verification must use an isolated target and meaningful concurrency tests, not application data.
- Older Logistics plans and evidence remain dated history. Use this ADR for the current support policy; do not rewrite historical results as dual-engine passes.
